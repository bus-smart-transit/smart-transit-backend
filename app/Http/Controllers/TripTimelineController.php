<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripTimelineShowRequest;
use App\Services\TripTimelineService;
use App\Traits\ApiResponse;

/**
 * A2: public, read-only passenger trip timeline. Recomputes server-side for a
 * chosen boarding/alighting stop or custom drop-off pin; nothing the client
 * sends (times, fares, snapped points) is trusted.
 */
class TripTimelineController extends Controller
{
    use ApiResponse;

    public function __construct(private TripTimelineService $timelineService) {}

    public function show(TripTimelineShowRequest $request, int $tripId)
    {
        $selection = $request->validated();

        return $this->success($this->timelineService->build($tripId, $selection), 'Trip timeline retrieved successfully');
    }
}
