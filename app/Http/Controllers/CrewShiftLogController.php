<?php

namespace App\Http\Controllers;

use App\Services\CrewShiftLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** §4.8: GET operator/crew-shifts?date=YYYY-MM-DD. The crew's time in and time out on the Operator's buses; read only. */
class CrewShiftLogController extends Controller
{
    use ApiResponse;

    public function __construct(private CrewShiftLogService $crewShiftLogs) {}

    public function index(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        // Batch 46: a date range (From..To, at most 31 days), a staff filter and pages. Without "from" it is one day.
        if ($request->has('from')) {
            $validated = $request->validate([
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
                'staff_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            ], ['to.after_or_equal' => 'The end date must be on or after the start date.']);
            if (Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) + 1 > CrewShiftLogService::MAX_RANGE_DAYS) {
                return $this->error('Choose at most '.CrewShiftLogService::MAX_RANGE_DAYS.' days.', 422);
            }

            return $this->success($this->crewShiftLogs->forOperatorBetween(
                (int) $companyUser->company_user_id,
                $validated['from'],
                $validated['to'],
                isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
                (int) ($validated['page'] ?? 1),
            ), 'Crew shift times retrieved');
        }

        $date = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']])['date'] ?? CrewShiftLogService::today();

        return $this->success([
            'date' => $date,
            'shifts' => $this->crewShiftLogs->forOperatorOnDate((int) $companyUser->company_user_id, $date),
        ], 'Crew shift times retrieved');
    }
}
