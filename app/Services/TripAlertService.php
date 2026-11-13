<?php

namespace App\Services;

use App\Models\StaffUser;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripAlertRepository;
use App\Repositories\TripRepository;
use App\Services\Sms\SmsNotifier;
use App\Support\CompanyScope;
use App\Support\LiveUpdates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Batch 58 (user, 2026-11-10): the one owner of crew alerts ("Alert operator").
 *
 * - The trip's own Driver or Chauffeur raises one (config alerts.types) while the trip is boarding, delayed or on the
 *   road. It records where the bus was (the phone's position, else a recent GPS report, else nothing).
 * - Every Operator of the trip's company, the Company Admin included, is told at once (bell, push, live update).
 * - A second press of the same type within alerts.repeat_window_seconds returns the alert already open.
 * - The crew member may take it back within alerts.cancel_window_seconds.
 * - An Operator marks it handled, and may tell the trip's passengers (once, never automatically: user's choice).
 * - It alerts the bus company only; it never calls the police or an ambulance.
 */
class TripAlertService
{
    public function __construct(
        private TripAlertRepository $alerts,
        private TripRepository $trips,
        private FleetLocationRepository $locations,
        private TicketRepository $tickets,
        private FcmService $fcm,
    ) {}

    /** @return array<string, string> key => label */
    public static function types(): array
    {
        return (array) config('alerts.types', []);
    }

    /** @throws ValidationException */
    public function raise(User $user, int $tripId, string $type, ?string $note, ?float $lat, ?float $lng): array
    {
        $staffId = (int) $user->companyProfile?->company_user_id;
        $trip = $this->trips->findById($tripId);
        $role = $this->crewRoleOn($trip, $user, $staffId);

        if (! in_array($trip->status, (array) config('alerts.raise_statuses'), true)) {
            throw ValidationException::withMessages(['trip' => ['You can send an alert from boarding until the trip ends.']]);
        }

        $existing = $this->alerts->findOpenSince($tripId, $type, now()->subSeconds((int) config('alerts.repeat_window_seconds')));
        if ($existing) {
            return $this->present($existing, $user) + ['repeated' => true];
        }

        [$latitude, $longitude, $source] = $this->placeOf($trip, $lat, $lng);
        $alert = $this->alerts->create([
            'trip_id' => $tripId,
            'raised_by_company_user_id' => $staffId,
            'raised_role' => $role,
            'type' => $type,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_source' => $source,
            'status' => TripAlert::OPEN,
        ]);
        $alert = $this->alerts->find((int) $alert->alert_id);

        $this->tellOperators($alert, 'Crew alert: '.$this->typeLabel($alert->type), $this->operatorText($alert));
        $this->announce($alert);

        return $this->present($alert, $user) + ['repeated' => false];
    }

    /** @throws ValidationException */
    public function cancel(User $user, int $alertId): array
    {
        $alert = $this->alerts->find($alertId) ?? throw new NotFoundHttpException('Alert not found.');
        if ((int) $alert->raised_by_company_user_id !== (int) $user->companyProfile?->company_user_id) {
            throw new AccessDeniedHttpException('Only the person who sent this alert can take it back.');
        }
        if ($alert->status !== TripAlert::OPEN || ! $this->withinCancelWindow($alert)) {
            throw ValidationException::withMessages(['alert' => ['This alert can no longer be taken back. Call your operator if it was a mistake.']]);
        }

        $alert = $this->alerts->update($alert, ['status' => TripAlert::CANCELLED, 'cancelled_at' => now()]);
        $this->tellOperators($alert, 'Crew alert taken back', $this->raiserName($alert).' took back the '.strtolower($this->typeLabel($alert->type)).' alert on '.$this->busText($alert).'.');
        $this->announce($alert);

        return $this->present($alert, $user);
    }

    /** @throws ValidationException */
    public function handle(User $operator, int $alertId, ?string $note): array
    {
        $alert = $this->operatorAlert($operator, $alertId);
        if ($alert->status !== TripAlert::OPEN) {
            throw ValidationException::withMessages(['alert' => [$alert->status === TripAlert::CANCELLED ? 'The crew took this alert back.' : 'This alert is already marked handled.']]);
        }

        $alert = $this->alerts->update($alert, [
            'status' => TripAlert::HANDLED,
            'handled_by_company_user_id' => $operator->companyProfile?->company_user_id,
            'handled_at' => now(),
            'handled_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);
        if ($alert->raisedBy?->user) {
            $this->fcm->sendToUser($alert->raisedBy->user, 'Your alert was handled', 'Your operator marked your '.strtolower($this->typeLabel($alert->type)).' alert as handled.'.($alert->handled_note ? ' Note: '.$alert->handled_note : ''), ['type' => 'crew_alert_handled', 'trip_id' => $alert->trip_id, 'alert_id' => $alert->alert_id]);
        }
        $this->announce($alert);

        return $this->present($alert, $operator);
    }

    /** Tells the trip's passengers (with an account) that the bus is delayed, once. @throws ValidationException */
    public function tellPassengers(User $operator, int $alertId): array
    {
        $alert = $this->operatorAlert($operator, $alertId);
        if ($alert->status === TripAlert::CANCELLED) {
            throw ValidationException::withMessages(['alert' => ['The crew took this alert back, so passengers are not told.']]);
        }
        if ($alert->passengers_told_at !== null) {
            throw ValidationException::withMessages(['alert' => ['Passengers were already told.']]);
        }

        $text = str_replace('{route}', $alert->trip?->fleetRoute?->route?->route_name ?? 'Your trip', (string) (config('alerts.passenger_notice')[$alert->type] ?? ''));
        $told = 0;
        foreach ($this->tickets->findActivePassengersForTrip((int) $alert->trip_id) as $passenger) {
            if (! $passenger->user) {
                continue;
            }
            $this->fcm->sendToUser($passenger->user, 'Your bus is delayed', $text, ['type' => 'trip_alert_notice', 'trip_id' => $alert->trip_id]);
            $told++;
        }
        // Batch 64: by text as well, to every purchase still on the trip (cash buyers who gave a number included).
        app(SmsNotifier::class)->tellPassengers((int) $alert->trip_id, $text);

        $alert = $this->alerts->update($alert, [
            'passengers_told_by_company_user_id' => $operator->companyProfile?->company_user_id,
            'passengers_told_at' => now(),
            'passengers_told_count' => $told,
        ]);
        $this->announce($alert);

        return $this->present($alert, $operator);
    }

    /** @return list<array> */
    public function listForOperator(User $operator): array
    {
        $since = now()->subHours((int) config('alerts.recent_hours'));

        return $this->alerts->listForOperator((int) $operator->companyProfile?->company_user_id, $since)
            ->map(fn (TripAlert $alert) => $this->present($alert, $operator))->values()->all();
    }

    /** @return list<array> The alerts on a trip the caller crews. */
    public function listForCrew(User $user, int $tripId): array
    {
        $this->crewRoleOn($this->trips->findById($tripId), $user, (int) $user->companyProfile?->company_user_id);

        return $this->alerts->listForTrip($tripId)->map(fn (TripAlert $alert) => $this->present($alert, $user))->values()->all();
    }

    /** What every screen shows about an alert. Missing values are null ("Not provided"). */
    public function present(TripAlert $alert, ?User $viewer = null): array
    {
        $trip = $alert->trip;
        $fleet = $trip?->fleetRoute?->fleet;
        $person = fn (?StaffUser $staff) => $staff ? ['name' => $staff->name, 'phone' => $staff->phone_num ?: null] : null;
        $viewerId = (int) $viewer?->companyProfile?->company_user_id;

        return [
            'alert_id' => (int) $alert->alert_id,
            'trip_id' => (int) $alert->trip_id,
            'type' => $alert->type,
            'type_label' => $this->typeLabel($alert->type),
            'note' => $alert->note,
            'status' => $alert->status,
            'raised_role' => $alert->raised_role,
            'raised_by' => $person($alert->raisedBy),
            'raised_at' => $alert->created_at?->toIso8601String(),
            'latitude' => $alert->latitude,
            'longitude' => $alert->longitude,
            'location_source' => $alert->location_source,
            'can_cancel' => $viewerId === (int) $alert->raised_by_company_user_id && $alert->status === TripAlert::OPEN && $this->withinCancelWindow($alert),
            'cancel_until' => $alert->created_at?->copy()->addSeconds((int) config('alerts.cancel_window_seconds'))->toIso8601String(),
            'cancelled_at' => $alert->cancelled_at?->toIso8601String(),
            'handled_at' => $alert->handled_at?->toIso8601String(),
            'handled_by' => $alert->handledBy?->name,
            'handled_note' => $alert->handled_note,
            'passengers_told_at' => $alert->passengers_told_at?->toIso8601String(),
            'passengers_told_count' => $alert->passengers_told_count,
            'trip' => $trip ? [
                'trip_date' => $trip->trip_date?->toDateString(),
                'departure_time' => $trip->departure_time ? substr((string) $trip->departure_time, 0, 5) : null,
                'status' => $trip->status,
                'route_name' => $trip->fleetRoute?->route?->route_name,
                // Batch 60: the Operator's "Track bus" opens this bus on Fleets.
                'fleet_id' => $fleet?->fleet_id,
                'plate_number' => $fleet?->plate_number,
                'body_no' => $fleet?->body_no,
                'driver' => $person($trip->driver),
                'conductor' => $person($trip->conductor),
            ] : null,
        ];
    }

    private function typeLabel(string $type): string
    {
        return self::types()[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /** "driver" or "conductor" when the user crews this trip. */
    private function crewRoleOn(?Trip $trip, User $user, int $staffId): string
    {
        if (! $trip) {
            throw new NotFoundHttpException('Trip not found.');
        }
        if ($user->role === 'driver' && $staffId && (int) $trip->driver_id === $staffId) {
            return 'driver';
        }
        if ($user->role === 'conductor' && $staffId && (int) $trip->conductor_id === $staffId) {
            return 'conductor';
        }
        throw new AccessDeniedHttpException('You are not the crew of this trip.');
    }

    private function operatorAlert(User $operator, int $alertId): TripAlert
    {
        $alert = $this->alerts->find($alertId) ?? throw new NotFoundHttpException('Alert not found.');
        if (! CompanyScope::sameCompany((int) $operator->companyProfile?->company_user_id, (int) $alert->trip?->company_user_id)) {
            throw new NotFoundHttpException('Alert not found.');
        }

        return $alert;
    }

    /** @return array{0: ?float, 1: ?float, 2: ?string} */
    private function placeOf(Trip $trip, ?float $lat, ?float $lng): array
    {
        if ($lat !== null && $lng !== null) {
            return [$lat, $lng, 'phone'];
        }
        $fleetId = $trip->fleetRoute?->fleet_id;
        $gps = $fleetId ? $this->locations->getCurrentForTrip((int) $fleetId, (int) $trip->trip_id, true) : null;
        if ($gps && ($gps['age_seconds'] ?? PHP_INT_MAX) <= (int) config('alerts.gps_max_age_seconds')) {
            return [(float) $gps['latitude'], (float) $gps['longitude'], 'gps'];
        }

        return [null, null, null];
    }

    private function withinCancelWindow(TripAlert $alert): bool
    {
        return $alert->created_at !== null
            && Carbon::parse($alert->created_at)->addSeconds((int) config('alerts.cancel_window_seconds'))->isFuture();
    }

    /** Every Operator of the trip's company (the Company Admin is one of them). */
    private function operators(TripAlert $alert): Collection
    {
        return StaffUser::with('user')
            ->whereIn('company_user_id', CompanyScope::operatorIdsFor((int) $alert->trip?->company_user_id))
            ->get()
            ->pluck('user')
            ->filter();
    }

    private function tellOperators(TripAlert $alert, string $title, string $body): void
    {
        foreach ($this->operators($alert) as $operator) {
            $this->fcm->sendToUser($operator, $title, $body, ['type' => 'crew_alert', 'trip_id' => $alert->trip_id, 'alert_id' => $alert->alert_id]);
        }
    }

    private function announce(TripAlert $alert): void
    {
        LiveUpdates::alert(LiveUpdates::companyKey((int) $alert->trip?->company_user_id), $this->present($alert));
    }

    private function raiserName(TripAlert $alert): string
    {
        return $alert->raisedBy?->name ?: ($alert->raised_role === 'conductor' ? 'The Chauffeur' : 'The Driver');
    }

    private function busText(TripAlert $alert): string
    {
        $fleet = $alert->trip?->fleetRoute?->fleet;
        $bus = $fleet?->body_no ?: ($fleet?->plate_number ?: 'the bus');

        return trim($bus.' ('.($alert->trip?->fleetRoute?->route?->route_name ?? 'route not provided').')');
    }

    private function operatorText(TripAlert $alert): string
    {
        $role = $alert->raised_role === 'conductor' ? 'Chauffeur' : 'Driver';

        return $this->raiserName($alert)." ({$role}) reported a ".strtolower($this->typeLabel($alert->type)).' on '.$this->busText($alert).'.'
            .($alert->note ? ' Note: '.$alert->note : '');
    }
}
