<?php

namespace App\Http\Controllers;

use App\Services\CrewShiftLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

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

        $date = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']])['date'] ?? CrewShiftLogService::today();

        return $this->success([
            'date' => $date,
            'shifts' => $this->crewShiftLogs->forOperatorOnDate((int) $companyUser->company_user_id, $date),
        ], 'Crew shift times retrieved');
    }
}
