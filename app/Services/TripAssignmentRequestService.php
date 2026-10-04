<?php

namespace App\Services;

use App\Models\TripAssignmentRequest;
use App\Repositories\FleetDailyPinRepository;
use App\Repositories\StaffRepository;
use App\Repositories\TripAssignmentRequestRepository;
use App\Repositories\TripNotificationRepository;
use App\Repositories\TripRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch 24 (C6): a driver or Chauffeur asks to be released from a trip
 * assignment. The request is an assignment-level status ("For Approval", D6)
 * and never touches the trip lifecycle. The Operator decides:
 *  - approve: the staff member is released and the trip surfaces as needing a
 *    replacement (re-assignment still goes through the one-trip-per-day,
 *    availability and pairing rules in TripService::assignDriver/Conductor);
 *  - reject: the staff member stays assigned and is told to proceed.
 * Declining is impossible once the trip has started or the assignment has
 * been accepted (Batch 17 bug family).
 */
class TripAssignmentRequestService
{
    public const REASON_CODES = ['sick', 'vehicle_issue', 'schedule_conflict', 'other'];
    public const REASON_TEXT_MAX = 300;

    public function __construct(
        private TripRepository $tripRepository,
        private TripAssignmentRequestRepository $requestRepository,
        private StaffRepository $staffRepository,
        private FleetDailyPinRepository $pinRepository,
        private TripNotificationRepository $notificationRepository,
        private FcmService $fcmService,
        private ShiftBlockAssignmentService $blockAssignments,
    ) {
    }

    /** Free text is plain text only: tags stripped, whitespace squished, length capped. */
    public static function sanitizeReasonText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = Str::of(strip_tags($text))->squish()->limit(self::REASON_TEXT_MAX, '')->toString();

        return $clean === '' ? null : $clean;
    }

    public function requestDecline(int $tripId, string $role, int $staffCompanyUserId, ?string $reasonCode, ?string $reasonText): object
    {
        if (! in_array($role, ['driver', 'conductor'], true)) {
            throw ValidationException::withMessages(['role' => ['Only a driver or conductor can decline a trip.']]);
        }

        $trip = $this->tripRepository->findById($tripId);
        if (! $trip) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        if ($trip->shift_block_id) {
            throw ValidationException::withMessages([
                'trip' => ['This trip is a leg of a shift block. Decline the whole shift block instead.'],
            ]);
        }

        if (! in_array($trip->status, ['scheduled', 'delayed', 'boarding'], true)) {
            throw ValidationException::withMessages([
                'status' => ['This trip has already departed and can no longer be declined.'],
            ]);
        }

        $assignedId = $role === 'driver' ? $trip->driver_id : $trip->conductor_id;
        if ((int) $assignedId !== $staffCompanyUserId) {
            throw ValidationException::withMessages([
                'trip' => ['You are not the assigned '.$role.' for this trip.'],
            ]);
        }

        $acceptedAt = $role === 'driver' ? $trip->driver_accepted_at : $trip->conductor_accepted_at;
        if ($acceptedAt) {
            throw ValidationException::withMessages([
                'status' => ['You already accepted this trip and can no longer decline it.'],
            ]);
        }

        $existing = $this->requestRepository->latestFor($tripId, $role);
        if ($existing && (int) $existing->staff_company_user_id === $staffCompanyUserId) {
            if ($existing->status === TripAssignmentRequest::FOR_APPROVAL) {
                return $this->tripWithRequests($tripId); // idempotent retry
            }
            if ($existing->status === TripAssignmentRequest::REJECTED) {
                throw ValidationException::withMessages([
                    'status' => ['Your request for this trip was rejected. Please proceed with the trip.'],
                ]);
            }
        }

        $text = self::sanitizeReasonText($reasonText);
        if ($reasonCode === 'other' && $text === null) {
            throw ValidationException::withMessages(['reason_text' => ['Please describe your reason.']]);
        }

        $this->requestRepository->create([
            'trip_id' => $tripId,
            'role' => $role,
            'staff_company_user_id' => $staffCompanyUserId,
            'reason_code' => $reasonCode,
            'reason_text' => $text,
            'status' => TripAssignmentRequest::FOR_APPROVAL,
        ]);

        $staff = $this->staffRepository->findById($staffCompanyUserId);
        $staffName = $staff?->name ?: ucfirst($role);
        $routeName = $trip->fleetRoute?->route?->route_name ?? 'Unassigned route';
        $reasonSuffix = $this->reasonSuffix($reasonCode, $text);

        $this->notificationRepository->create([
            'trip_id' => $tripId,
            'type' => 'trip_decline_requested',
            'title' => "Trip #{$tripId}: ".ucfirst($role).' decline request',
            'message' => "{$staffName} asked to be released from the {$role} assignment for {$routeName} on {$trip->trip_date}.{$reasonSuffix} Approve or reject the request.",
            'actor_company_user_id' => $staffCompanyUserId,
        ]);

        $operatorUser = $trip->companyUser?->user;
        if ($operatorUser) {
            $this->fcmService->sendToUser(
                $operatorUser,
                'Trip Decline Request',
                "{$staffName} asked to be released from the {$role} assignment for {$routeName} on {$trip->trip_date}.{$reasonSuffix}",
                ['type' => 'trip_decline_requested', 'trip_id' => $tripId],
            );
        }

        return $this->tripWithRequests($tripId);
    }

    /**
     * @param 'approve'|'reject' $decision
     */
    public function decide(int $requestId, string $decision, int $operatorCompanyUserId): object
    {
        $request = $this->requestRepository->findById($requestId);
        if (! $request) {
            throw ValidationException::withMessages(['request' => ['Request not found.']]);
        }

        $trip = $request->trip;
        if (! $trip || (int) $trip->company_user_id !== $operatorCompanyUserId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You do not have permission to decide this request.');
        }

        if ($request->status !== TripAssignmentRequest::FOR_APPROVAL) {
            throw ValidationException::withMessages(['request' => ['This request has already been decided.']]);
        }

        // A request that belongs to a shift block is decided for the whole block at once.
        if ($blockRecord = $this->blockAssignments->recordForLegRequest($request)) {
            $this->blockAssignments->decide($blockRecord, $decision, $operatorCompanyUserId);

            return $this->requestRepository->findById($requestId);
        }

        $approve = $decision === 'approve';
        if ($approve && ! in_array($trip->status, ['scheduled', 'delayed', 'boarding'], true)) {
            throw ValidationException::withMessages([
                'status' => ['This trip has already departed, so the assignment can no longer be released.'],
            ]);
        }

        $newStatus = $approve ? TripAssignmentRequest::APPROVED : TripAssignmentRequest::REJECTED;

        DB::transaction(function () use ($request, $trip, $approve, $newStatus, $operatorCompanyUserId) {
            if (! $this->requestRepository->decide($request->id, $newStatus, $operatorCompanyUserId)) {
                throw ValidationException::withMessages(['request' => ['This request has already been decided.']]);
            }

            if ($approve) {
                // Release only if the requester still holds the assignment.
                $assignedId = $request->role === 'driver' ? $trip->driver_id : $trip->conductor_id;
                if ((int) $assignedId === (int) $request->staff_company_user_id) {
                    $request->role === 'driver'
                        ? $this->tripRepository->clearDriver($trip->trip_id)
                        : $this->tripRepository->clearConductor($trip->trip_id);

                    // The PIN was generated for a pair that no longer both hold their assignment.
                    $this->pinRepository->deleteTodayByTrip($trip->trip_id);
                }
            }
        });

        $routeName = $trip->fleetRoute?->route?->route_name ?? 'the trip';
        $staff = $request->staff;
        if ($staff?->user) {
            $this->fcmService->sendToUser(
                $staff->user,
                $approve ? 'Decline Request Approved' : 'Decline Request Rejected',
                $approve
                    ? "You have been released from {$routeName} on {$trip->trip_date}."
                    : "Your request for {$routeName} on {$trip->trip_date} was rejected. Please proceed with the trip.",
                ['type' => $approve ? 'decline_approved' : 'decline_rejected', 'trip_id' => $trip->trip_id],
            );
        }

        if ($approve) {
            $this->notificationRepository->create([
                'trip_id' => $trip->trip_id,
                'type' => 'trip_declined',
                'title' => "Trip #{$trip->trip_id} needs a replacement ".$request->role,
                'message' => ($staff?->name ?: ucfirst($request->role))." was released from the {$request->role} assignment for {$routeName} on {$trip->trip_date}. The trip is now unassigned and needs a replacement.",
                'actor_company_user_id' => $operatorCompanyUserId,
            ]);
        }

        return $this->requestRepository->findById($requestId);
    }

    /**
     * One row per shift block and requester: a block's per-leg requests collapse to its first leg's
     * request, which carries the block id and the number of legs.
     */
    public function pendingForOperator(int $operatorCompanyUserId)
    {
        $pending = $this->requestRepository->listPendingForOperator($operatorCompanyUserId);

        return $pending
            ->groupBy(fn ($r) => $r->trip?->shift_block_id
                ? 'block-'.$r->trip->shift_block_id.'-'.$r->role.'-'.$r->staff_company_user_id
                : 'trip-'.$r->id)
            ->map(function ($group) {
                $first = $group->sortBy('id')->first();
                $blockId = $first->trip?->shift_block_id;
                $first->setAttribute('shift_block_id', $blockId);
                $first->setAttribute('legs_count', $blockId ? $group->count() : 1);

                return $first;
            })
            ->values();
    }

    public function pendingCountForOperator(int $operatorCompanyUserId): int
    {
        return $this->pendingForOperator($operatorCompanyUserId)->count();
    }

    private function tripWithRequests(int $tripId): object
    {
        return $this->tripRepository->findById($tripId)->load('assignmentRequests');
    }

    private function reasonSuffix(?string $reasonCode, ?string $text): string
    {
        if ($text) {
            return " Reason: {$text}";
        }

        return $reasonCode ? ' Reason: '.str_replace('_', ' ', $reasonCode) : '';
    }
}
