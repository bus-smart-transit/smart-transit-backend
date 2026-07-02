<?php

namespace App\Http\Controllers;

use App\Services\StopService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class StopController extends Controller
{
    use ApiResponse;
    private StopService $stopService;

    public function __construct(StopService $stopService)
    {
        $this->stopService = $stopService;
    }

    public function index()
    {
        return $this->success($this->stopService->listStops(), 'Stops retrieved successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'stop_name' => 'required|string',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        return $this->success($this->stopService->createStop($validated), 'Stop created successfully');
    }

    public function update(Request $request, int $stopId)
    {
        $validated = $request->validate([
            'stop_name' => 'sometimes|string',
            'latitude' => 'sometimes|numeric',
            'longitude' => 'sometimes|numeric',
        ]);

        return $this->success($this->stopService->updateStop($stopId, $validated), 'Stop updated successfully');
    }

    public function destroy(int $stopId)
    {
        $this->stopService->deleteStop($stopId);
        return $this->success(null, 'Stop deleted successfully');
    }
}
