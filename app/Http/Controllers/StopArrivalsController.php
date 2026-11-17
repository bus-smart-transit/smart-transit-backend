<?php

namespace App\Http\Controllers;

use App\Http\Requests\StopArrivalsRequest;
use App\Services\StopArrivalsService;
use App\Traits\ApiResponse;

/** Batch 77 (user, 2026-11-17): the buses coming to a stop, for the Track Bus stop pin card. Public, read-only. */
class StopArrivalsController extends Controller
{
    use ApiResponse;

    public function __construct(private StopArrivalsService $arrivals) {}

    public function show(StopArrivalsRequest $request, string $code)
    {
        $limit = $request->validated('limit');
        $data = $this->arrivals->forStopCode($code, $limit !== null ? (int) $limit : null);

        return $data === null
            ? $this->error('Stop not found.', 404)
            : $this->success($data, 'Arrivals retrieved successfully');
    }
}
