<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStopRequest;
use App\Http\Requests\UpdateStopRequest;
use App\Services\StopService;
use App\Support\CompanyScope;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StopController extends Controller
{
    use ApiResponse;

    public function __construct(private StopService $stopService) {}

    /** 2026-11-06: staff see their own company's stops; the platform Admin sees every stop. */
    public function index(Request $request)
    {
        return $this->success($this->stopService->listStops(CompanyScope::companyFilterFor($request->user())), 'Stops retrieved successfully');
    }

    /** 2026-11-06: a stop belongs to the creating company admin's company (the platform Admin's has none). */
    public function store(StoreStopRequest $request)
    {
        $payload = $request->validated();
        if ($request->user()?->role !== 'admin') {
            $payload['company_id'] = $request->user()?->companyProfile?->company_id;
        }

        return $this->success($this->stopService->createStop($payload), 'Stop created successfully');
    }

    public function update(UpdateStopRequest $request, int $stopId)
    {
        if ($denied = $this->ownStopOr404($request, $stopId)) {
            return $denied;
        }

        return $this->success($this->stopService->updateStop($stopId, $request->validated()), 'Stop updated successfully');
    }

    public function destroy(Request $request, int $stopId)
    {
        if ($denied = $this->ownStopOr404($request, $stopId)) {
            return $denied;
        }
        $this->stopService->deleteStop($stopId);

        return $this->success(null, 'Stop deleted successfully');
    }

    /** Another company's stop reads as not found; one still used by another company's route cannot be changed. */
    private function ownStopOr404(Request $request, int $stopId): ?JsonResponse
    {
        $stop = $this->stopService->findStop($stopId);
        if (! $stop || ! CompanyScope::ownsRow($stop->company_id, $request->user())) {
            return $this->error('Stop not found.', 404);
        }
        if ($request->user()?->role !== 'admin' && $this->stopService->usedByAnotherCompany($stopId, (int) $stop->company_id)) {
            return $this->error('Another company\'s route also uses this stop, so it cannot be changed. Add your own stop instead.', 422);
        }

        return null;
    }
}
