<?php

namespace App\Http\Controllers;

use App\Services\TripTimelineService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * A2: public, read-only passenger trip timeline. Recomputes server-side for a
 * chosen boarding/alighting stop or custom drop-off pin; nothing the client
 * sends (times, fares, snapped points) is trusted.
 */
class TripTimelineController extends Controller
{
    use ApiResponse;

    public function __construct(private TripTimelineService $timelineService) {}

    public function show(Request $request, int $tripId)
    {
        $selection = $request->validate([
            'boarding_stop_id' => 'nullable|integer|min:1',
            'alighting_stop_id' => 'nullable|integer|min:1',
            'dropoff_lat' => 'nullable|numeric|between:-90,90|required_with:dropoff_lng',
            'dropoff_lng' => 'nullable|numeric|between:-180,180|required_with:dropoff_lat',
            'seat_type' => 'nullable|in:seated,standing',
        ]);

        return $this->success($this->timelineService->build($tripId, $selection), 'Trip timeline retrieved successfully');
    }
}