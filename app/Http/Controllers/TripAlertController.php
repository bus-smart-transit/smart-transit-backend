<?php

namespace App\Http\Controllers;

use App\Http\Requests\HandleTripAlertRequest;
use App\Http\Requests\RaiseTripAlertRequest;
use App\Services\TripAlertService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Batch 58: crew alerts ("Alert operator"). Thin: TripAlertService owns every rule, and the caller is always the signed-in
 * user (nothing about who they are is taken from the request).
 */
class TripAlertController extends Controller
{
    use ApiResponse;

    public function __construct(private TripAlertService $alerts) {}

    public function raise(RaiseTripAlertRequest $request, int $tripId)
    {
        $data = $request->validated();
        $alert = $this->alerts->raise(
            $request->user(),
            $tripId,
            $data['type'],
            $data['note'] ?? null,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
        );

        return $this->success($alert, $alert['repeated'] ? 'Your operator already has this alert.' : 'Alert sent to your operator.', $alert['repeated'] ? 200 : 201);
    }

    public function forTrip(Request $request, int $tripId)
    {
        // The types come with the list, so the crew's buttons are never written into the website.
        return $this->success([
            'alerts' => $this->alerts->listForCrew($request->user(), $tripId),
            'types' => collect(TripAlertService::types())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
        ], 'Alerts retrieved');
    }

    public function cancel(Request $request, int $alertId)
    {
        return $this->success($this->alerts->cancel($request->user(), $alertId), 'Alert taken back.');
    }

    public function index(Request $request)
    {
        return $this->success($this->alerts->listForOperator($request->user()), 'Alerts retrieved');
    }

    public function handle(HandleTripAlertRequest $request, int $alertId)
    {
        return $this->success($this->alerts->handle($request->user(), $alertId, $request->validated('note')), 'Alert marked handled.');
    }

    public function tellPassengers(Request $request, int $alertId)
    {
        $alert = $this->alerts->tellPassengers($request->user(), $alertId);

        return $this->success($alert, $alert['passengers_told_count'] === 1 ? '1 passenger was told.' : "{$alert['passengers_told_count']} passengers were told.");
    }
}
