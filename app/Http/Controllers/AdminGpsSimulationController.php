<?php

namespace App\Http\Controllers;

use App\Http\Requests\ControlGpsSimulationRequest;
use App\Http\Requests\StartGpsSimulationRequest;
use App\Services\GpsSimulationService;
use App\Traits\ApiResponse;

/**
 * Batch 49 item 4: the Super Admin "GPS simulator" screen (start, pause, resume, stop, jump to a stop, speed). Rules
 * and checks live in GpsSimulationService; every write is audit-logged there and by the admin.audit middleware.
 */
class AdminGpsSimulationController extends Controller
{
    use ApiResponse;

    public function __construct(private GpsSimulationService $simulator) {}

    public function index()
    {
        return $this->success($this->simulator->overview(), 'GPS simulator');
    }

    public function store(StartGpsSimulationRequest $request)
    {
        $simulation = $this->simulator->start($request->user(), (int) $request->validated('trip_id'), (int) $request->validated('speed'));

        return $this->success($this->simulator->present($simulation), 'You have control of this bus\'s GPS location.', 201);
    }

    public function update(ControlGpsSimulationRequest $request, int $simulationId)
    {
        $data = $request->validated();
        $simulation = $this->simulator->control(
            $simulationId,
            $data['action'],
            isset($data['stop_id']) ? (int) $data['stop_id'] : null,
            isset($data['speed']) ? (int) $data['speed'] : null,
        );

        return $this->success($this->simulator->present($simulation), 'Simulation updated');
    }
}
