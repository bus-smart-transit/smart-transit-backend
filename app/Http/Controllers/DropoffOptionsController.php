<?php

namespace App\Http\Controllers;

use App\Repositories\TripRepository;
use App\Services\CustomDropoffService;
use App\Support\ServiceType;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Batch 28 Stage 3, item 10: public, read-only. What a drop-off screen needs for one trip: the route's drop-off mode,
 * the restricted areas to shade, and (designated-points mode) the points ahead of the boarding position. Official
 * stops are never part of it and are never blocked. The client uses this for feedback only: the server re-checks
 * every choice when the timeline is built and again at checkout.
 */
class DropoffOptionsController extends Controller
{
    use ApiResponse;

    public function __construct(private CustomDropoffService $dropoff, private TripRepository $trips) {}

    public function show(Request $request, int $tripId)
    {
        $data = $request->validate([
            'boarding_stop_id' => 'nullable|integer|min:1',
            'boarding_along_m' => 'nullable|numeric|min:0',
        ]);

        $trip = $this->trips->findById($tripId);
        if (! $trip || ! $trip->fleetRoute) {
            return $this->error('Trip not found.', 404);
        }

        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        if (ServiceType::isExpress($trip->service_type)) {
            return $this->success([
                'mode' => 'off', 'available' => false, 'reason' => 'Custom drop-off is not available on an express trip.',
                'restricted_areas' => [], 'needs_review' => [], 'designated_points' => [],
            ], 'Drop-off options retrieved successfully');
        }

        $options = $this->dropoff->options(
            (int) $trip->fleetRoute->route_id,
            $direction,
            isset($data['boarding_stop_id']) ? (int) $data['boarding_stop_id'] : null,
            isset($data['boarding_along_m']) ? (float) $data['boarding_along_m'] : null,
        );

        return $this->success($options + ['route_id' => (int) $trip->fleetRoute->route_id, 'leg_direction' => $direction], 'Drop-off options retrieved successfully');
    }
}
