<?php

namespace App\Http\Controllers;

use App\Http\Requests\RescheduleLegRequest;
use App\Services\AuditLogger;
use App\Services\ShiftBlockService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Batch 18: Shift Block Hand-off System.
 */
class ShiftBlockController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ShiftBlockService $shiftBlockService,
    ) {}

    // ── Operator/Dispatcher ──────────────────────────────────────────────

    public function index(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $blocks = $this->shiftBlockService->listForOperator(
            $companyUser->company_user_id,
            $request->query('status_filter'),
            (int) ($pagination['page'] ?? 1),
            (int) ($pagination['per_page'] ?? 100),
        );

        return $this->paginated($blocks, 'Shift blocks retrieved successfully');
    }

    public function show(Request $request, int $shiftBlockId)
    {
        $block = $this->shiftBlockService->findById($shiftBlockId);

        if ($denied = $this->assertOperatorOwnsBlock($request, $block)) {
            return $denied;
        }

        return $this->success($block, 'Shift block retrieved successfully');
    }

    public function store(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $validated = $request->validate([
            'fleet_route_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
            'conductor_id' => ['required', 'integer'],
            'block_type' => ['required', 'string'],
            'scheduled_date' => ['required', 'date'],
            'service_type' => ['sometimes', 'in:regular,express'],
            'leg_departure_times' => ['required', 'array'],
            'leg_departure_times.*' => ['required', 'date_format:H:i'],
        ]);

        $block = $this->shiftBlockService->createBlock(array_merge($validated, [
            'company_user_id' => $companyUser->company_user_id,
        ]));

        AuditLogger::log('shift_block.create', 'ShiftBlock', $block->shift_block_id, [
            'driver_id' => $validated['driver_id'],
            'conductor_id' => $validated['conductor_id'],
            'block_type' => $validated['block_type'],
        ]);

        // 2026-10-27 (§4.2): the planner's flags for the saved times (overtime, driving limit, rest, night work).
        $block->setAttribute('planning', $this->shiftBlockService->planningReview($block));

        return $this->success($block, 'Shift block created successfully');
    }

    /** 2026-10-27 (§4.2): the Operator moves one leg that has not started to a new time. */
    public function rescheduleLeg(RescheduleLegRequest $request, int $shiftBlockId, int $tripId)
    {
        $block = $this->shiftBlockService->findById($shiftBlockId);
        if ($denied = $this->assertOperatorOwnsBlock($request, $block)) {
            return $denied;
        }

        $result = $this->shiftBlockService->rescheduleLeg($shiftBlockId, $tripId, (string) $request->validated('departure_time'), $request->user());

        return $this->success($result, 'Leg time changed');
    }

    /** Phase B (§4.13): POST shift-blocks/range/preview. What each day From..To would be; nothing is saved. */
    public function previewRange(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $payload = array_merge($this->validateRange($request), ['company_user_id' => $companyUser->company_user_id]);

        return $this->success($this->shiftBlockService->previewRange($payload), 'Schedule preview ready');
    }

    /** Phase B: POST shift-blocks/range. Creates the clean days and lists the skipped ones with the reason. */
    public function storeRange(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $validated = $this->validateRange($request);
        $result = $this->shiftBlockService->createRange(array_merge($validated, ['company_user_id' => $companyUser->company_user_id]));

        foreach ($result['days'] as $day) {
            if (isset($day['shift_block_id'])) {
                AuditLogger::log('shift_block.create', 'ShiftBlock', $day['shift_block_id'], [
                    'driver_id' => $validated['driver_id'],
                    'conductor_id' => $validated['conductor_id'],
                    'block_type' => $validated['block_type'],
                    'range' => [$result['from_date'], $result['to_date']],
                ]);
            }
        }

        $message = $result['create_count'] === 1 ? '1 day scheduled' : "{$result['create_count']} days scheduled";

        return $this->success($result, $result['skip_count'] > 0 ? "{$message}, {$result['skip_count']} skipped" : $message);
    }

    /** The single-block fields, with a From and To date in place of scheduled_date (the range cap is the service's). */
    private function validateRange(Request $request): array
    {
        return $request->validate([
            'fleet_route_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
            'conductor_id' => ['required', 'integer'],
            'block_type' => ['required', 'string'],
            'from_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'service_type' => ['sometimes', 'in:regular,express'],
            'leg_departure_times' => ['required', 'array'],
            'leg_departure_times.*' => ['required', 'date_format:H:i'],
        ], [
            'from_date.after_or_equal' => 'The From date cannot be in the past.',
            'to_date.after_or_equal' => 'The To date must be on or after the From date.',
        ]);
    }

    public function update(Request $request, int $shiftBlockId)
    {
        $block = $this->shiftBlockService->findById($shiftBlockId);
        if ($denied = $this->assertOperatorOwnsBlock($request, $block)) {
            return $denied;
        }

        $validated = $request->validate([
            'driver_id' => ['nullable', 'integer'],
            'conductor_id' => ['nullable', 'integer'],
        ]);

        $updated = $this->shiftBlockService->updateAssignment($shiftBlockId, $validated);

        AuditLogger::log('shift_block.update', 'ShiftBlock', $shiftBlockId, $validated);

        return $this->success($updated, 'Shift block updated successfully');
    }

    public function cancel(Request $request, int $shiftBlockId)
    {
        $block = $this->shiftBlockService->findById($shiftBlockId);
        if ($denied = $this->assertOperatorOwnsBlock($request, $block)) {
            return $denied;
        }

        $cancelled = $this->shiftBlockService->cancelBlock($shiftBlockId, $request->user());

        AuditLogger::log('shift_block.cancel', 'ShiftBlock', $shiftBlockId);

        return $this->success($cancelled, 'Shift block cancelled successfully');
    }

    public function pendingReview(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success(
            $this->shiftBlockService->listPendingReview($companyUser->company_user_id),
            'Pending-review shift blocks retrieved successfully',
        );
    }

    public function markReviewed(Request $request, int $shiftBlockId)
    {
        $block = $this->shiftBlockService->findById($shiftBlockId);
        if ($denied = $this->assertOperatorOwnsBlock($request, $block)) {
            return $denied;
        }

        $reviewed = $this->shiftBlockService->markReviewed($shiftBlockId);

        AuditLogger::log('shift_block.mark_reviewed', 'ShiftBlock', $shiftBlockId);

        return $this->success($reviewed, 'Shift block marked reviewed');
    }

    // Section 4.3: Dispatcher manual override — exception handling when
    // automated GPS-based hand-off isn't available/hasn't triggered.
    public function overrideHandoff(Request $request, int $shiftBlockId)
    {
        $block = $this->shiftBlockService->findById($shiftBlockId);
        if ($denied = $this->assertOperatorOwnsBlock($request, $block)) {
            return $denied;
        }

        $result = $this->shiftBlockService->initiateHandoff($shiftBlockId, null, override: true);

        AuditLogger::log('shift_block.override_handoff', 'ShiftBlock', $shiftBlockId);

        return $this->success($result, 'Hand-off forced by Dispatcher.');
    }

    public function overrideTakeover(Request $request, int $shiftBlockId)
    {
        $block = $this->shiftBlockService->findById($shiftBlockId);
        if ($denied = $this->assertOperatorOwnsBlock($request, $block)) {
            return $denied;
        }

        $result = $this->shiftBlockService->confirmTakeover($shiftBlockId, null, override: true);

        AuditLogger::log('shift_block.override_takeover', 'ShiftBlock', $shiftBlockId);

        return $this->success($result, 'Takeover forced by Dispatcher.');
    }

    // ── Driver ───────────────────────────────────────────────────────────

    public function myBlocksAsDriver(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->paginated($this->shiftBlockService->listForDriver($companyUser->company_user_id, (int) ($pagination['page'] ?? 1), (int) ($pagination['per_page'] ?? 50)), 'Shift blocks retrieved successfully');
    }

    public function confirmTakeover(Request $request, int $shiftBlockId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $block = $this->shiftBlockService->confirmTakeover($shiftBlockId, $companyUser->company_user_id);

        AuditLogger::log('shift_block.confirm_takeover', 'ShiftBlock', $shiftBlockId);

        return $this->success($block, 'Takeover confirmed. Shift block is now in progress.');
    }

    // ── Conductor ────────────────────────────────────────────────────────

    public function myBlocksAsConductor(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->paginated($this->shiftBlockService->listForConductor($companyUser->company_user_id, (int) ($pagination['page'] ?? 1), (int) ($pagination['per_page'] ?? 50)), 'Shift blocks retrieved successfully');
    }

    public function handoffEligibility(Request $request, int $shiftBlockId)
    {
        return $this->success($this->shiftBlockService->evaluateHandoffEligibility($shiftBlockId), 'Eligibility evaluated');
    }

    public function initiateHandoff(Request $request, int $shiftBlockId)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $block = $this->shiftBlockService->initiateHandoff($shiftBlockId, $companyUser->company_user_id);

        AuditLogger::log('shift_block.initiate_handoff', 'ShiftBlock', $shiftBlockId);

        return $this->success($block, 'Hand-off initiated. Your session will now be signed out.');
    }

    private function assertOperatorOwnsBlock(Request $request, object $block)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'operator') {
            return null;
        }

        $companyUserId = $user->companyProfile?->company_user_id;
        if (! $companyUserId) {
            return $this->error('Staff profile not found.', 404);
        }

        if ((int) $block->company_user_id !== (int) $companyUserId) {
            return $this->error('You do not have permission to modify this shift block.', 403);
        }

        return null;
    }
}
