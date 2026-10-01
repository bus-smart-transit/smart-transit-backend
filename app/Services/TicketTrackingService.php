<?php

namespace App\Services;

use App\Models\Ticket;
use App\Repositories\FleetLocationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Batch 26: follow the bus you bought a ticket for, without an account.
 *
 * A ticket carries one opaque, unguessable token (256 bits). The link (`/track/<token>`) is the
 * whole credential, so what it returns is deliberately small and scoped to that ticket's trip:
 * the bus position projected on the route, the trip status and the ETA at the ticket's own
 * boarding and alighting stops. Never the driver or conductor, the plate or fleet, other
 * passengers or other buses. It works only from shortly before departure until a grace period
 * after the trip ends (config/tracking.php) and does not unlock the QR code.
 */
class TicketTrackingService
{
    // Ticket states a link may follow: not already expired, cancelled or refunded.
    private const TRACKABLE = ['valid', 'boarded', 'alighted'];

    private const TOKEN_FORMAT = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private TripTimelineService $timelineService,
        private FleetLocationRepository $locations,
        private RouteSnapService $routeSnapService,
    ) {}

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{token: string, tracking_token_hash: string, tracking_token_enc: string} */
    public function newCredentials(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'tracking_token_hash' => self::hashOf($token), 'tracking_token_enc' => $token];
    }

    /**
     * The tracking token of a ticket, for its owner. New tickets get one when they are created;
     * an older ticket gets one the first time its owner opens it (only if it is recent enough to
     * still matter). Null when the ticket can no longer be tracked.
     */
    public function tokenFor(Ticket $ticket): ?string
    {
        if ($ticket->tracking_token_enc) {
            return $ticket->tracking_token_enc;
        }
        if (! in_array($ticket->status, self::TRACKABLE, true)) {
            return null;
        }
        $tripDate = $ticket->trip?->trip_date;
        $oldest = Carbon::now(config('app.timezone'))->startOfDay()->subDays((int) config('tracking.backfill_days'));
        if (! $tripDate || $tripDate->lt($oldest)) {
            return null;
        }

        $credentials = $this->newCredentials();
        // Only the first writer wins; whoever lost the race reads the winner's token below.
        Ticket::where('ticket_id', $ticket->ticket_id)->whereNull('tracking_token_hash')->update([
            'tracking_token_hash' => $credentials['tracking_token_hash'],
            'tracking_token_enc' => Crypt::encryptString($credentials['token']),
        ]);
        $stored = Ticket::where('ticket_id', $ticket->ticket_id)->first(['tracking_token_hash', 'tracking_token_enc']);
        if (! $stored) {
            return null;
        }
        // Raw attributes, synced as original, so the ticket is not left dirty.
        $ticket->setRawAttributes(array_merge($ticket->getAttributes(), $stored->getAttributes()), true);

        return $ticket->tracking_token_enc;
    }

    public function findByToken(string $token): ?Ticket
    {
        if (! preg_match(self::TOKEN_FORMAT, $token)) {
            return null;
        }

        return Ticket::with(['trip.fleetRoute.route', 'trip.fleetRoute.fleet', 'originStop', 'destinationStop'])
            ->where('tracking_token_hash', self::hashOf($token))
            ->first();
    }

    /**
     * What the link shows. `state` is one of: tracking, stale, no_signal, waiting, not_yet,
     * arrived, ended, cancelled, unavailable.
     *
     * @return array<string, mixed>
     */
    public function viewFor(Ticket $ticket): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $trip = $ticket->trip;
        $fleetRoute = $trip?->fleetRoute;

        $view = [
            'state' => 'unavailable',
            'message' => 'This ticket can no longer be tracked.',
            'ticket' => ['status' => $ticket->status, 'seat_type' => $ticket->seat_type],
            'refresh' => ['poll_seconds' => (int) config('tracking.poll_seconds'), 'generated_at' => $now->toIso8601String()],
        ];

        $time = $trip?->departure_time ?: $fleetRoute?->start_time;
        if (! $trip || ! $fleetRoute || ! $trip->trip_date || ! $time || ! in_array($ticket->status, self::TRACKABLE, true)) {
            return $view;
        }

        $start = Carbon::parse($trip->trip_date->toDateString().' '.$time, $timezone);
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $grace = (int) config('tracking.grace_minutes_after');
        $opensAt = $start->copy()->subMinutes((int) config('tracking.opens_minutes_before'));
        $closesAt = $start->copy()->addHours((int) config('tracking.max_active_hours'))->addMinutes($grace);
        if ($trip->status === 'completed') {
            $closesAt = Carbon::parse($trip->completed_at ?? $start, $timezone)->addMinutes($grace);
        }

        $view['trip'] = [
            'trip_date' => $trip->trip_date->toDateString(),
            'departure_time' => $start->format('H:i'),
            'status' => $trip->status,
            'route_name' => $fleetRoute->route?->route_name,
        ];
        $view['route'] = ['route_id' => (int) $fleetRoute->route_id, 'direction' => $direction];
        $view['boarding'] = $this->stopSummary($ticket->originStop, $ticket->origin_lat, $ticket->origin_lng, null);
        $view['alighting'] = $this->stopSummary($ticket->destinationStop, $ticket->destination_lat, $ticket->destination_lng, $ticket->destination_label);
        $view['bus'] = null;

        if ($trip->status === 'cancelled') {
            return ['state' => 'cancelled', 'message' => 'This trip was cancelled.'] + $view;
        }
        if ($now->lt($opensAt)) {
            return ['state' => 'not_yet', 'message' => 'Tracking opens shortly before departure.', 'opens_at' => $opensAt->toIso8601String()] + $view;
        }
        if ($now->gt($closesAt)) {
            return ['state' => 'ended', 'message' => 'This trip has ended, so tracking has closed.'] + $view;
        }

        $view = $this->withEta($view, $ticket, $trip->trip_id);

        if ($trip->status === 'completed') {
            return ['state' => 'arrived', 'message' => 'The bus has completed this trip.'] + $view;
        }

        $position = $this->locations->getCurrentForTrip((int) $fleetRoute->fleet_id, (int) $trip->trip_id);
        if ($position === null) {
            $waiting = in_array($trip->status, ['scheduled', 'delayed'], true);

            return [
                'state' => $waiting ? 'waiting' : 'no_signal',
                'message' => $waiting ? 'The bus has not left yet.' : 'The bus has not shared its position yet.',
            ] + $view;
        }

        $snap = $this->routeSnapService->snap((int) $fleetRoute->route_id, $position['latitude'], $position['longitude'], $direction);
        $onRoute = (bool) ($snap['on_route'] ?? false);
        $view['bus'] = [
            // Projected onto the route line when it is on it, the raw fix otherwise.
            'latitude' => $onRoute ? $snap['snapped_latitude'] : $position['latitude'],
            'longitude' => $onRoute ? $snap['snapped_longitude'] : $position['longitude'],
            'heading' => $position['heading'],
            'on_route' => $onRoute,
            'age_seconds' => $position['age_seconds'],
            'recorded_at' => $now->copy()->subSeconds($position['age_seconds'])->toIso8601String(),
        ];

        $stale = $position['age_seconds'] > (int) config('tracking.stale_seconds');

        return [
            'state' => $stale ? 'stale' : 'tracking',
            'message' => $stale ? 'The last known position is a little old.' : null,
        ] + $view;
    }

    /** @return array<string, mixed> */
    private function stopSummary($stop, $lat, $lng, ?string $label): array
    {
        if ($stop) {
            return ['stop_id' => (int) $stop->stop_id, 'stop_code' => $stop->stop_code, 'name' => $stop->stop_name, 'custom' => false, 'eta_time' => null, 'eta' => null, 'eta_source' => null, 'progress' => null];
        }

        return ['stop_id' => null, 'stop_code' => null, 'name' => $label ?: 'Custom stop', 'custom' => $lat !== null, 'latitude' => $lat, 'longitude' => $lng, 'eta_time' => null, 'eta' => null, 'eta_source' => null, 'progress' => null];
    }

    /** ETAs at the ticket's own two stops, from the same timeline the trip page uses. */
    private function withEta(array $view, Ticket $ticket, int $tripId): array
    {
        if (! $ticket->origin_stop_id) {
            return $view;
        }

        $selection = ['boarding_stop_id' => (int) $ticket->origin_stop_id];
        if ($ticket->destination_stop_id) {
            $selection['alighting_stop_id'] = (int) $ticket->destination_stop_id;
        } elseif ($ticket->destination_lat !== null && $ticket->destination_lng !== null) {
            $selection['dropoff_lat'] = (float) $ticket->destination_lat;
            $selection['dropoff_lng'] = (float) $ticket->destination_lng;
        }

        try {
            $timeline = $this->timelineService->build($tripId, $selection);
        } catch (ValidationException) {
            return $view; // the ETA is simply left out; nothing is guessed
        }

        $rows = collect($timeline['stops']);
        $pick = fn (array $key) => $rows->first(fn ($row) => $key['custom'] ? ($row['custom'] ?? false) : (int) $row['stop_id'] === (int) $key['stop_id']);
        foreach (['boarding', 'alighting'] as $side) {
            $row = $pick($view[$side]);
            if ($row) {
                $view[$side] = array_merge($view[$side], [
                    'eta_time' => $row['eta_time'],
                    'eta' => $row['eta'],
                    'eta_source' => $row['eta_source'],
                    'progress' => $row['progress'],
                ]);
            }
        }
        $view['refresh']['eta_basis'] = $timeline['refresh']['eta_basis'];

        return $view;
    }
}
