<?php

namespace App\Support;

use App\Models\Trip;
use App\Models\User;
use App\Services\RouteSnapService;
use App\Services\TripProgressService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Batch 58 (user, 2026-11-10): the one place that sends live updates over WebSocket (Laravel Reverb), and the one place
 * that names the channels and says who may listen (routes/channels.php asks it).
 *
 * Screens still poll as a fallback, so a send that fails never fails the action that caused it: it is logged (no
 * payload), recorded for System Health, and the caller goes on. Nothing is sent unless broadcasting is set to Reverb.
 *
 * Channels:
 * - fleet-positions (public): "bus.moved", a running bus's new position, the same data the public map already shows;
 * - trip-status (public): "trip.status", a trip id and its new status only;
 * - private user.{user_id}: "notification", a new bell notification for that person;
 * - private company.{key}: "alert", a crew alert for every Operator of the company (key: company id, or u{company_user_id}
 *   for an Operator without a company, the CompanyScope "company of one").
 */
final class LiveUpdates
{
    public const POSITIONS = 'fleet-positions';

    public const TRIP_STATUS = 'trip-status';

    public static function companyKey(?int $companyUserId): ?string
    {
        if (! $companyUserId) {
            return null;
        }
        $companyId = CompanyScope::companyIdOf($companyUserId);

        return $companyId !== null ? (string) $companyId : 'u'.$companyUserId;
    }

    /** May this signed-in user listen to company.{key}? Operators of that company only. */
    public static function mayListenToCompany(?User $user, string $key): bool
    {
        if (! $user || $user->role !== 'operator') {
            return false;
        }
        $companyUserId = $user->companyProfile?->company_user_id;

        return $companyUserId !== null && self::companyKey((int) $companyUserId) === $key;
    }

    /** A running bus's new position (phone or simulator), with its place on the route; nothing while it waits to depart. */
    public static function busMoved(array $location): void
    {
        self::send('bus.moved', function () use ($location) {
            $tripId = isset($location['trip_id']) ? (int) $location['trip_id'] : null;
            $trip = $tripId ? Trip::with('fleetRoute')->find($tripId) : null;
            if (! $trip || ! TripProgressService::isRunning($trip->status)) {
                return;
            }
            $lat = (float) $location['latitude'];
            $lng = (float) $location['longitude'];
            $routeId = $trip->fleetRoute?->route_id;

            Broadcast::on(self::POSITIONS)->as('bus.moved')->with([
                'fleet_id' => (int) $location['fleet_id'],
                'trip_id' => $tripId,
                'trip_status' => $trip->status,
                'latitude' => $lat,
                'longitude' => $lng,
                'heading' => $location['heading'] ?? null,
                'speed_kmh' => $location['speed_kmh'] ?? null,
                'is_simulated' => (bool) ($location['is_simulated'] ?? false),
                'recorded_at' => now()->toIso8601String(),
                'route_position' => $routeId
                    ? app(RouteSnapService::class)->snap((int) $routeId, $lat, $lng, $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound')
                    : null,
            ])->sendNow();
        });
    }

    public static function tripStatus(int $tripId, string $status): void
    {
        self::send('trip.status', fn () => Broadcast::on(self::TRIP_STATUS)->as('trip.status')
            ->with(['trip_id' => $tripId, 'status' => $status])->sendNow());
    }

    /** A new bell notification for one person: the screen reloads its list (the text is not sent here). */
    public static function notification(int $userId, string $type, array $data = []): void
    {
        self::send('notification', fn () => Broadcast::private('user.'.$userId)->as('notification')
            ->with(['type' => $type, 'trip_id' => $data['trip_id'] ?? null, 'alert_id' => $data['alert_id'] ?? null])->sendNow());
    }

    /** A crew alert was raised, cancelled, handled or passed on to passengers: the Operators' screens reload it. */
    public static function alert(?string $companyKey, array $alert): void
    {
        if ($companyKey === null) {
            return;
        }
        self::send('alert', fn () => Broadcast::on(new PrivateChannel('company.'.$companyKey))->as('alert')->with($alert)->sendNow());
    }

    /**
     * What a signed-in person's screens may listen to: always the public channels; their own private channel; and, for an
     * Operator, the company channel.
     *
     * @return array{enabled: bool, positions: string, trip_status: string, user: ?string, company: ?string}
     */
    public static function channelsFor(?User $user): array
    {
        $companyUserId = $user?->companyProfile?->company_user_id;

        return [
            'enabled' => self::enabled(),
            'positions' => self::POSITIONS,
            'trip_status' => self::TRIP_STATUS,
            'user' => $user ? 'user.'.$user->user_id : null,
            'company' => $user?->role === 'operator' && $companyUserId ? 'company.'.self::companyKey((int) $companyUserId) : null,
        ];
    }

    public static function enabled(): bool
    {
        return in_array((string) config('broadcasting.default'), ['reverb', 'pusher'], true);
    }

    private static function send(string $what, callable $send): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            $send();
            // Written at most once a minute while it keeps working (a GPS update arrives every few seconds).
            $last = HealthBeat::last('reverb');
            if (! $last || ! $last['ok'] || now()->diffInSeconds($last['at'], true) >= 60) {
                HealthBeat::record('reverb', true);
            }
        } catch (Throwable $e) {
            Log::warning('Live update not sent; screens fall back to polling.', ['event' => $what, 'error' => class_basename($e)]);
            HealthBeat::record('reverb', false, 'A live update could not be sent.');
        }
    }
}
