<?php

namespace App\Services;

use App\Models\ShiftBlock;
use App\Models\ShiftBlockAssignment;
use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAssignmentRequest;
use App\Repositories\FleetDailyPinRepository;
use App\Repositories\TripAssignmentRequestRepository;
use App\Repositories\TripNotificationRepository;
use App\Repositories\TripRepository;
use App\Support\ScheduleStatus;
use App\Support\ServiceType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batch 28 (item 1): the Driver, the Chauffeur and the Operator deal with a shift block as ONE card.
 *
 * Accepting or declining happens once, on the block, and writes the block-level record
 * (shift_block_assignments) AND every leg in a single transaction, so the two can never
 * disagree. A standalone one-way / ad hoc trip is a block of one leg (key "trip-<id>").
 *
 * The existing leg-level machinery (trips.*_accepted_at, trip_assignment_requests) is kept and written
 * through: the "For Approval" badge, the Operator request list and old clients keep working.
 *
 * Rules (the same as the leg-level ones, applied to the whole block):
 *  - accept or decline only while no leg has started and the block is not cancelled;
 *  - decline is impossible after the block was accepted;
 *  - the Operator approving a decline releases the person from the WHOLE block, which then needs a
 *    replacement; rejecting keeps the person assigned and tells them to proceed.
 */
class ShiftBlockAssignmentService
{
    public const ROLES = ['driver', 'conductor'];

    /** Leg statuses that still allow a response: nothing has left the terminal. */
    private const OPEN_STATUSES = ['scheduled', 'delayed', 'boarding'];

    public function __construct(
        private TripRepository $tripRepository,
        private TripAssignmentRequestRepository $requestRepository,
        private TripNotificationRepository $notificationRepository,
        private FleetDailyPinRepository $pinRepository,
        private FcmService $fcmService,
    ) {}

    // ---------------------------------------------------------------- records

    /** Make sure (and reconcile) both roles' records of a block. Called after a block is created or reassigned. */
    public function ensureForBlock(ShiftBlock $block): void
    {
        $legs = Trip::where('shift_block_id', $block->shift_block_id)->get();
        foreach (self::ROLES as $role) {
            $this->reconcile(['shift_block_id' => $block->shift_block_id, 'trip_id' => null], $role, $block->{$role.'_id'}, $legs);
        }
    }

    /**
     * The record behind a card, created or reconciled with the legs. Returns null when nobody holds
     * the role (the released / unassigned state: there is nothing to respond to).
     *
     * @param  array{shift_block_id: int|null, trip_id: int|null}  $key
     */
    private function reconcile(array $key, string $role, ?int $staffId, Collection $legs): ?ShiftBlockAssignment
    {
        $record = ShiftBlockAssignment::where('role', $role)
            ->where($key['shift_block_id'] ? 'shift_block_id' : 'trip_id', $key['shift_block_id'] ?: $key['trip_id'])
            ->first();

        if (! $staffId) {
            if ($record && $record->status !== ShiftBlockAssignment::RELEASED) {
                $record->update(['status' => ShiftBlockAssignment::RELEASED]);
            }

            return null;
        }

        if (! $record) {
            $record = ShiftBlockAssignment::create($key + ['role' => $role, 'staff_company_user_id' => $staffId, 'status' => ShiftBlockAssignment::PENDING]);
        } elseif ((int) $record->staff_company_user_id !== $staffId || $record->status === ShiftBlockAssignment::RELEASED) {
            // A replacement (or the same person assigned again after a release) starts with a clean
            // slate, as it does for a single trip.
            $record->update([
                'staff_company_user_id' => $staffId,
                'status' => ShiftBlockAssignment::PENDING,
                'accepted_at' => null, 'reason_code' => null, 'reason_text' => null,
                'requested_at' => null, 'decided_by_company_user_id' => null, 'decided_at' => null,
            ]);
        }

        // Leg-level actions made through the older single-trip endpoints are folded in.
        if (in_array($record->status, [ShiftBlockAssignment::PENDING, ShiftBlockAssignment::REJECTED, ShiftBlockAssignment::RELEASED], true)) {
            $live = $legs->filter(fn ($leg) => $leg->status !== 'cancelled');
            if ($live->isNotEmpty() && $live->every(fn ($leg) => $leg->{$role.'_accepted_at'} !== null)) {
                $record->update(['status' => ShiftBlockAssignment::ACCEPTED, 'accepted_at' => $live->max(fn ($leg) => $leg->{$role.'_accepted_at'})]);
            }
        }
        if ($record->status === ShiftBlockAssignment::PENDING) {
            $latest = TripAssignmentRequest::whereIn('trip_id', $legs->pluck('trip_id'))
                ->where('role', $role)->where('staff_company_user_id', $staffId)->orderByDesc('id')->first();
            if ($latest && $latest->status === TripAssignmentRequest::FOR_APPROVAL) {
                $record->update([
                    'status' => ShiftBlockAssignment::FOR_APPROVAL,
                    'reason_code' => $latest->reason_code, 'reason_text' => $latest->reason_text, 'requested_at' => $latest->created_at,
                ]);
            }
        }

        return $record->fresh();
    }

    // ------------------------------------------------------------------ cards

    /**
     * The staff member's cards: one per shift block plus one per standalone trip, soonest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cardsFor(string $role, int $staffId): array
    {
        $this->assertRole($role);
        $column = $role.'_id';
        $since = now(config('app.timezone', 'Asia/Manila'))->subDays((int) config('shift_blocks.card_history_days'))->toDateString();
        $cards = [];

        $blocks = ShiftBlock::with(['fleet', 'legs.fleetRoute.route', 'legs.fleetRoute.fleet'])
            ->where($column, $staffId)
            ->where('status', '!=', 'cancelled')
            ->whereDate('scheduled_date', '>=', $since)
            ->get();
        foreach ($blocks as $block) {
            // Batch 29: what a block IS comes from its legs, not from the stored workflow column.
            if (ScheduleStatus::forLegs($block->legs, $block->status) === 'cancelled') {
                continue;
            }
            $record = $this->reconcile(['shift_block_id' => $block->shift_block_id, 'trip_id' => null], $role, $staffId, $block->legs);
            if ($record) {
                $cards[] = $this->card($block, null, $block->legs, $record, $role);
            }
        }

        $trips = Trip::with(['fleetRoute.route', 'fleetRoute.fleet'])
            ->whereNull('shift_block_id')
            ->where($column, $staffId)
            ->where('status', '!=', 'cancelled')
            ->whereDate('trip_date', '>=', $since)
            ->get();
        foreach ($trips as $trip) {
            $record = $this->reconcile(['shift_block_id' => null, 'trip_id' => $trip->trip_id], $role, $staffId, collect([$trip]));
            if ($record) {
                $cards[] = $this->card(null, $trip, collect([$trip]), $record, $role);
            }
        }

        usort($cards, fn ($a, $b) => [$a['scheduled_date'], $a['start_time']] <=> [$b['scheduled_date'], $b['start_time']]);

        return $cards;
    }

    /**
     * Operator view: one card per shift block with both roles' assignment states.
     *
     * @return array<int, array<string, mixed>>
     */
    public function operatorBlockCards(int $operatorCompanyUserId, ?int $limit = null): array
    {
        $limit ??= (int) config('shift_blocks.operator_card_limit');
        $blocks = ShiftBlock::with(['fleet', 'legs.fleetRoute.route', 'legs.fleetRoute.fleet'])
            ->where('company_user_id', $operatorCompanyUserId)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('scheduled_date')
            ->limit($limit)
            ->get();

        return $blocks->map(function (ShiftBlock $block) {
            $card = $this->card($block, null, $block->legs, null, null);
            $card['assignments'] = [];
            foreach (self::ROLES as $role) {
                $staffId = $block->{$role.'_id'};
                $record = $this->reconcile(['shift_block_id' => $block->shift_block_id, 'trip_id' => null], $role, $staffId, $block->legs);
                $card['assignments'][$role] = [
                    'staff_id' => $staffId,
                    'name' => $staffId ? StaffUser::find($staffId)?->name : null,
                    'status' => $record?->status ?? ShiftBlockAssignment::RELEASED,
                    'needs_replacement' => $staffId === null,
                    'reason_code' => $record?->reason_code,
                    'reason_text' => $record?->reason_text,
                ];
            }

            return $card;
        })->all();
    }

    /** @return array<string, mixed> */
    private function card(?ShiftBlock $block, ?Trip $trip, Collection $legs, ?ShiftBlockAssignment $record, ?string $role): array
    {
        $ordered = $legs->sortBy([['departure_time', 'asc'], ['trip_id', 'asc']])->values();
        $first = $ordered->first();
        $last = $ordered->last();
        $route = $first?->fleetRoute?->route;
        $date = $block ? $block->scheduled_date?->toDateString() : $first?->trip_date?->toDateString();
        $time = fn (?Trip $leg) => $leg ? substr((string) ($leg->departure_time ?: $leg->fleetRoute?->start_time), 0, 5) : null;
        // Batch 29, issue 1: ONE status for every portal, from the leg statuses (never the stored block column).
        $status = ScheduleStatus::forLegs($ordered, $block ? $block->status : $first?->status);
        $today = ScheduleStatus::today();
        $section = ScheduleStatus::section($date, $status, $today);
        $answerable = $this->isOpen($ordered) && ! ScheduleStatus::isPastDate($date, $today);
        $waiting = $record !== null && in_array($record->status, [ShiftBlockAssignment::PENDING, ShiftBlockAssignment::REJECTED], true);

        return [
            'key' => $block ? 'block-'.$block->shift_block_id : 'trip-'.$trip->trip_id,
            'kind' => $block ? 'block' : 'trip',
            'shift_block_id' => $block?->shift_block_id,
            'trip_id' => $block ? null : $trip->trip_id,
            'first_trip_id' => $first?->trip_id,
            'route_name' => $route?->route_name,
            'origin' => $route?->origin,
            'destination' => $route?->destination,
            'scheduled_date' => $date,
            'start_time' => $time($first),
            'end_time' => $time($last),
            'legs_count' => $ordered->count(),
            'status' => $status,
            'block_type' => $block?->block_type,
            'service_type' => ServiceType::normalize($first?->service_type),
            // Batch 28 Stage 3, item 8: a block of several legs is a "Round-trip run"; the service says which stops an express serves.
            'run_pattern' => \App\Support\TripRunInfo::runPattern($first?->trip_type, $block?->shift_block_id ? (int) $block->shift_block_id : null, $block ? $ordered->count() : null),
            'service' => $first ? \App\Support\TripRunInfo::forTrip($first)['service'] : null,
            'plate_number' => ($block?->fleet ?? $first?->fleetRoute?->fleet)?->plate_number,
            'role' => $role,
            'assignment_status' => $record?->status,
            'accepted_at' => $record?->accepted_at?->toIso8601String(),
            'reason_code' => $record?->reason_code,
            'reason_text' => $record?->reason_text,
            'can_respond' => $waiting && $answerable,
            'can_decline' => $record !== null && $record->status === ShiftBlockAssignment::PENDING && $answerable,
            // Why a card that is still "awaiting your response" cannot be answered (shown to the person; never blank).
            'respond_blocked_reason' => $waiting && ! $answerable ? $this->blockedReason($ordered, $date, $today) : null,
            'section' => $section,
            'is_overdue' => $section === ScheduleStatus::SECTION_OVERDUE,
            'overdue_reason' => $section === ScheduleStatus::SECTION_OVERDUE ? ScheduleStatus::PAST_DATE_MESSAGE : null,
            'legs' => $ordered->map(function (Trip $leg) {
                $reverse = $leg->leg_direction === 'reverse';
                $leg_route = $leg->fleetRoute?->route;

                return [
                    'trip_id' => $leg->trip_id,
                    'leg_direction' => $reverse ? 'reverse' : 'outbound',
                    'departure_time' => substr((string) ($leg->departure_time ?: $leg->fleetRoute?->start_time), 0, 5),
                    'status' => $leg->status,
                    'origin' => $reverse ? $leg_route?->destination : $leg_route?->origin,
                    'destination' => $reverse ? $leg_route?->origin : $leg_route?->destination,
                    'service_type' => ServiceType::normalize($leg->service_type),
                ];
            })->all(),
        ];
    }

    /** The plain reason a pending assignment cannot be answered now. */
    private function blockedReason(Collection $legs, ?string $date, string $today): string
    {
        if ($legs->every(fn ($leg) => $leg->status === 'cancelled')) {
            return 'This assignment was cancelled.';
        }
        if (ScheduleStatus::isPastDate($date, $today)) {
            return ScheduleStatus::PAST_DATE_MESSAGE;
        }
        if ($legs->reject(fn ($leg) => $leg->status === 'cancelled')->every(fn ($leg) => $leg->status === 'completed')) {
            return 'This assignment is finished.';
        }

        return 'The first leg has already started, so this can no longer be answered.';
    }

    /** True while the block can still be answered: live legs exist and none has started. */
    private function isOpen(Collection $legs): bool
    {
        $live = $legs->filter(fn ($leg) => $leg->status !== 'cancelled');

        return $live->isNotEmpty() && $live->every(fn ($leg) => in_array($leg->status, self::OPEN_STATUSES, true));
    }

    // ------------------------------------------------------ accept / decline

    /** @return array<string, mixed> the updated card */
    public function accept(string $key, string $role, int $staffId): array
    {
        $this->assertRole($role);

        $outcome = DB::transaction(function () use ($key, $role, $staffId) {
            [$block, $trip, $legs, $record] = $this->load($key, $role, $staffId);
            $this->assertOpen($legs, 'accepted');

            if ($record->status === ShiftBlockAssignment::ACCEPTED) {
                return [$block, $trip, $legs, $record, false]; // double tap: nothing to redo
            }
            if ($record->status === ShiftBlockAssignment::FOR_APPROVAL) {
                throw ValidationException::withMessages(['status' => ['You asked to be released. Wait for the Operator to decide before accepting.']]);
            }

            foreach ($legs->filter(fn ($leg) => $leg->status !== 'cancelled') as $leg) {
                $role === 'driver' ? $this->tripRepository->markDriverAccepted($leg->trip_id) : $this->tripRepository->markConductorAccepted($leg->trip_id);
            }
            $record->update(['status' => ShiftBlockAssignment::ACCEPTED, 'accepted_at' => now()]);

            return [$block, $trip, $legs, $record, true];
        });

        [$block, $trip, $legs, $record, $changed] = $outcome;
        if ($changed) {
            $this->notifyOperator($block, $trip, $legs, $role, $staffId, 'Shift Block Assignment Accepted', 'trip_accepted',
                fn ($name, $what) => "{$name} accepted the {$role} assignment for {$what}.");
        }

        return $this->cardFor($key, $role, $staffId);
    }

    /** @return array<string, mixed> the updated card */
    public function decline(string $key, string $role, int $staffId, ?string $reasonCode, ?string $reasonText): array
    {
        $this->assertRole($role);

        $text = TripAssignmentRequestService::sanitizeReasonText($reasonText);
        if ($reasonCode !== null && ! in_array($reasonCode, TripAssignmentRequestService::REASON_CODES, true)) {
            throw ValidationException::withMessages(['reason_code' => ['Choose one of the listed reasons.']]);
        }
        if ($reasonCode === 'other' && $text === null) {
            throw ValidationException::withMessages(['reason_text' => ['Please describe your reason.']]);
        }

        $outcome = DB::transaction(function () use ($key, $role, $staffId, $reasonCode, $text) {
            [$block, $trip, $legs, $record] = $this->load($key, $role, $staffId);
            $this->assertOpen($legs, 'declined');

            if ($record->status === ShiftBlockAssignment::ACCEPTED) {
                throw ValidationException::withMessages(['status' => ['You already accepted this and can no longer decline it.']]);
            }
            if ($record->status === ShiftBlockAssignment::REJECTED) {
                throw ValidationException::withMessages(['status' => ['Your request was rejected. Please proceed with the assignment.']]);
            }
            if ($record->status === ShiftBlockAssignment::FOR_APPROVAL) {
                return [$block, $trip, $legs, $record, false]; // idempotent retry
            }

            foreach ($legs->filter(fn ($leg) => $leg->status !== 'cancelled') as $leg) {
                $this->requestRepository->create([
                    'trip_id' => $leg->trip_id,
                    'role' => $role,
                    'staff_company_user_id' => $staffId,
                    'reason_code' => $reasonCode,
                    'reason_text' => $text,
                    'status' => TripAssignmentRequest::FOR_APPROVAL,
                ]);
            }
            $record->update([
                'status' => ShiftBlockAssignment::FOR_APPROVAL,
                'reason_code' => $reasonCode, 'reason_text' => $text, 'requested_at' => now(),
            ]);

            return [$block, $trip, $legs, $record, true];
        });

        [$block, $trip, $legs, $record, $changed] = $outcome;
        if ($changed) {
            $suffix = $text ? " Reason: {$text}" : ($reasonCode ? ' Reason: '.str_replace('_', ' ', $reasonCode) : '');
            $first = $legs->sortBy('trip_id')->first();
            $name = StaffUser::find($staffId)?->name ?: ucfirst($role);
            $what = $this->describe($block, $trip, $legs);

            $this->notificationRepository->create([
                'trip_id' => $first->trip_id,
                'type' => 'trip_decline_requested',
                'title' => ($block ? "Shift block #{$block->shift_block_id}" : "Trip #{$trip->trip_id}").': '.ucfirst($role).' decline request',
                'message' => "{$name} asked to be released from the {$role} assignment for {$what}.{$suffix} Approve or reject the request.",
                'actor_company_user_id' => $staffId,
            ]);
            $this->pushToOperator($first, 'Decline Request', "{$name} asked to be released from the {$role} assignment for {$what}.{$suffix}",
                ['type' => 'trip_decline_requested', 'trip_id' => $first->trip_id]);
        }

        return $this->cardFor($key, $role, $staffId);
    }

    // ---------------------------------------------------------- operator side

    /**
     * Operator decision on a block-level request. Approve releases the person from every leg of the
     * block and leaves it needing a replacement; reject keeps them assigned and tells them to proceed.
     *
     * @param  'approve'|'reject'  $decision
     */
    public function decide(ShiftBlockAssignment $assignment, string $decision, int $operatorCompanyUserId): ShiftBlockAssignment
    {
        $approve = $decision === 'approve';
        $role = $assignment->role;

        $result = DB::transaction(function () use ($assignment, $approve, $role, $operatorCompanyUserId) {
            $record = ShiftBlockAssignment::where('id', $assignment->id)->lockForUpdate()->first();
            $block = $record->shift_block_id ? ShiftBlock::find($record->shift_block_id) : null;
            $legs = $block ? Trip::where('shift_block_id', $block->shift_block_id)->get() : Trip::where('trip_id', $record->trip_id)->get();
            $owner = $block ? $block->company_user_id : $legs->first()?->company_user_id;

            if ((int) $owner !== $operatorCompanyUserId) {
                throw new AuthorizationException('You do not have permission to decide this request.');
            }
            if ($record->status !== ShiftBlockAssignment::FOR_APPROVAL) {
                throw ValidationException::withMessages(['request' => ['This request has already been decided.']]);
            }
            if ($approve && ! $this->isOpen($legs)) {
                throw ValidationException::withMessages(['status' => ['The first leg has already started, so the assignment can no longer be released.']]);
            }

            $staffId = (int) $record->staff_company_user_id;
            foreach ($legs as $leg) {
                TripAssignmentRequest::where('trip_id', $leg->trip_id)->where('role', $role)
                    ->where('staff_company_user_id', $staffId)->where('status', TripAssignmentRequest::FOR_APPROVAL)
                    ->update([
                        'status' => $approve ? TripAssignmentRequest::APPROVED : TripAssignmentRequest::REJECTED,
                        'decided_by_company_user_id' => $operatorCompanyUserId,
                        'decided_at' => now(),
                    ]);

                if ($approve && (int) $leg->{$role.'_id'} === $staffId) {
                    $role === 'driver' ? $this->tripRepository->clearDriver($leg->trip_id) : $this->tripRepository->clearConductor($leg->trip_id);
                    // The PIN was made for a pair that no longer both hold their assignment.
                    $this->pinRepository->deleteTodayByTrip($leg->trip_id);
                }
            }
            if ($approve && $block && (int) $block->{$role.'_id'} === $staffId) {
                $block->{$role.'_id'} = null;
                $block->save();
            }

            $record->update([
                'status' => $approve ? ShiftBlockAssignment::RELEASED : ShiftBlockAssignment::REJECTED,
                'decided_by_company_user_id' => $operatorCompanyUserId,
                'decided_at' => now(),
            ]);

            return [$record->fresh(), $block, $legs];
        });

        [$record, $block, $legs] = $result;
        $staff = StaffUser::with('user')->find($record->staff_company_user_id);
        $what = $this->describe($block, $legs->count() === 1 ? $legs->first() : null, $legs);
        $date = $block?->scheduled_date?->toDateString() ?? $legs->first()?->trip_date?->toDateString();

        if ($staff?->user) {
            $this->fcmService->sendToUser(
                $staff->user,
                $approve ? 'Decline Request Approved' : 'Decline Request Rejected',
                $approve
                    ? "You have been released from {$what} on {$date}."
                    : "Your request for {$what} on {$date} was rejected. Please proceed with the assignment.",
                ['type' => $approve ? 'decline_approved' : 'decline_rejected', 'trip_id' => $legs->sortBy('trip_id')->first()?->trip_id],
            );
        }
        if ($approve) {
            $this->notificationRepository->create([
                'trip_id' => $legs->sortBy('trip_id')->first()->trip_id,
                'type' => 'trip_declined',
                'title' => ($block ? "Shift block #{$block->shift_block_id}" : 'Trip #'.$legs->first()->trip_id)." needs a replacement {$record->role}",
                'message' => ($staff?->name ?: ucfirst($record->role))." was released from the {$record->role} assignment for {$what} on {$date}. It is now unassigned and needs a replacement.",
                'actor_company_user_id' => $operatorCompanyUserId,
            ]);
        }

        return $record;
    }

    /**
     * The block-level record a leg-level request belongs to, when it is still waiting for approval.
     * Used so the older Operator endpoints decide a whole block at once.
     */
    public function recordForLegRequest(TripAssignmentRequest $request): ?ShiftBlockAssignment
    {
        $trip = $request->trip;
        if (! $trip) {
            return null;
        }
        $legs = $trip->shift_block_id ? Trip::where('shift_block_id', $trip->shift_block_id)->get() : collect([$trip]);
        $key = $trip->shift_block_id ? ['shift_block_id' => $trip->shift_block_id, 'trip_id' => null] : ['shift_block_id' => null, 'trip_id' => $trip->trip_id];
        $record = $this->reconcile($key, $request->role, (int) $request->staff_company_user_id, $legs);

        return $record && $record->status === ShiftBlockAssignment::FOR_APPROVAL && (int) $record->staff_company_user_id === (int) $request->staff_company_user_id
            ? $record
            : null;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: ShiftBlock|null, 1: Trip|null, 2: Collection, 3: ShiftBlockAssignment} */
    private function load(string $key, string $role, int $staffId): array
    {
        if (! preg_match('/^(block|trip)-(\d+)$/', $key, $m)) {
            throw ValidationException::withMessages(['assignment' => ['Assignment not found.']]);
        }
        $id = (int) $m[2];

        if ($m[1] === 'block') {
            $block = ShiftBlock::with(['fleet', 'legs.fleetRoute.route'])->lockForUpdate()->find($id);
            $trip = null;
            $legs = $block ? Trip::where('shift_block_id', $id)->lockForUpdate()->get() : collect();
            $holder = $block?->{$role.'_id'};
            $recordKey = ['shift_block_id' => $id, 'trip_id' => null];
        } else {
            $block = null;
            $trip = Trip::with(['fleetRoute.route'])->whereNull('shift_block_id')->lockForUpdate()->find($id);
            $legs = $trip ? collect([$trip]) : collect();
            $holder = $trip?->{$role.'_id'};
            $recordKey = ['shift_block_id' => null, 'trip_id' => $id];
        }

        if ($legs->isEmpty() || (int) $holder !== $staffId) {
            // Same answer for "not found" and "not yours": no probing other people's assignments.
            throw ValidationException::withMessages(['assignment' => ['You are not the assigned '.$role.' for this assignment.']]);
        }

        $record = $this->reconcile($recordKey, $role, $staffId, $legs);

        return [$block, $trip, $legs, $record];
    }

    private function assertOpen(Collection $legs, string $verb): void
    {
        if ($legs->every(fn ($leg) => $leg->status === 'cancelled')) {
            throw ValidationException::withMessages(['status' => ['This assignment was cancelled.']]);
        }
        // Batch 29, issue 3: a date that has passed (Manila) can no longer be accepted or declined: contact the operator.
        $date = $legs->map(fn ($leg) => $leg->trip_date?->toDateString())->filter()->min();
        if (ScheduleStatus::isPastDate($date)) {
            throw ValidationException::withMessages(['status' => [ScheduleStatus::PAST_DATE_MESSAGE]]);
        }
        if (! $this->isOpen($legs)) {
            throw ValidationException::withMessages(['status' => ["The first leg has already started, so this can no longer be {$verb}."]]);
        }
    }

    /** @return array<string, mixed> */
    private function cardFor(string $key, string $role, int $staffId): array
    {
        foreach ($this->cardsFor($role, $staffId) as $card) {
            if ($card['key'] === $key) {
                return $card;
            }
        }

        return [];
    }

    private function describe(?ShiftBlock $block, ?Trip $trip, Collection $legs): string
    {
        $first = $legs->sortBy('trip_id')->first();
        $route = $first?->fleetRoute?->route?->route_name ?? 'the assignment';
        $date = $block?->scheduled_date?->toDateString() ?? $first?->trip_date?->toDateString();

        return $block ? "shift block #{$block->shift_block_id} ({$legs->count()} legs) on {$route} on {$date}" : "{$route} on {$date}";
    }

    private function notifyOperator(?ShiftBlock $block, ?Trip $trip, Collection $legs, string $role, int $staffId, string $title, string $type, callable $message): void
    {
        $first = $legs->sortBy('trip_id')->first();
        $name = StaffUser::find($staffId)?->name ?: ucfirst($role);
        $this->pushToOperator($first, $title, $message($name, $this->describe($block, $trip, $legs)), ['type' => $type, 'trip_id' => $first->trip_id]);
    }

    private function pushToOperator(Trip $leg, string $title, string $body, array $data): void
    {
        $operatorUser = $leg->companyUser?->user;
        if ($operatorUser) {
            $this->fcmService->sendToUser($operatorUser, $title, $body, $data);
        }
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => ['Only a driver or conductor can respond to an assignment.']]);
        }
    }
}
