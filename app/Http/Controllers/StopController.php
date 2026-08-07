<?php
namespace App\Http\Controllers;
use App\Services\StopService;
use App\Http\Requests\StoreStopRequest;
use App\Http\Requests\UpdateStopRequest;
use App\Traits\ApiResponse;

class StopController extends Controller
{
    use ApiResponse;
    public function __construct(private StopService $stopService)
    {
    }

    public function index()
    {
        return $this->success($this->stopService->listStops(), 'Stops retrieved successfully');
    }

    public function store(StoreStopRequest $request)
    {
        return $this->success($this->stopService->createStop($request->validated()), 'Stop created successfully');
    }

    public function update(UpdateStopRequest $request, int $stopId)
    {
        return $this->success($this->stopService->updateStop($stopId, $request->validated()), 'Stop updated successfully');
    }

    public function destroy(int $stopId)
    {
        $this->stopService->deleteStop($stopId);
        return $this->success(null, 'Stop deleted successfully');
    }
}
