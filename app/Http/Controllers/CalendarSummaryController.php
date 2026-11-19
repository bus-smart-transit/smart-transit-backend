<?php

namespace App\Http\Controllers;

use App\Services\CalendarSummaryService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * C8: one aggregate endpoint for Driver, Chauffeur and Operator. The scope is
 * always the authenticated user's own role and staff id; nothing is taken
 * from the request.
 */
class CalendarSummaryController extends Controller
{
    use ApiResponse;

    public function __construct(private CalendarSummaryService $service) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $companyUser = $user?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success(
            $this->service->summaryFor($user->role, $companyUser->company_user_id),
            'Calendar summary retrieved successfully'
        );
    }

    // The days in a range that have trips or shifts: the calendar widget's markers.
    public function days(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);
        $maxDays = (int) config('calendar.max_range_days');
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > $maxDays) {
            throw ValidationException::withMessages(['to' => ["Ask for at most $maxDays days at a time."]]);
        }

        return $this->success(
            ['from' => $data['from'], 'to' => $data['to'], 'days' => $this->service->markersFor($request->user()->role, $companyUser->company_user_id, $data['from'], $data['to'])],
            'Calendar days retrieved successfully'
        );
    }

    // The trips and shift legs of one day, paginated: the day list in the modal and on the calendar page.
    public function day(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.(int) config('calendar.day_max_per_page'),
        ]);

        return $this->success(
            $this->service->dayFor(
                $request->user()->role,
                $companyUser->company_user_id,
                $data['date'],
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? config('calendar.day_per_page')),
            ),
            'Calendar day retrieved successfully'
        );
    }
}
