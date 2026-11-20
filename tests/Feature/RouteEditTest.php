<?php

use App\Models\Company;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Payment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-20 (user): the Company Admin edits a route's name and its stop order. SAMPLE places.

function reOperator(Company $company, bool $admin = true): User
{
    $user = User::create(['username' => 're'.Str::random(6), 'email' => 're.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Re Op', 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => $admin]);

    return $user->fresh();
}

/** A route of the company with stops A, B, C (in that order). */
function reRoute(Company $company): array
{
    $route = Route::create(['route_name' => 'Old Name', 'origin' => 'A', 'destination' => 'C', 'company_id' => $company->company_id]);
    $routeStops = [];
    foreach (['A', 'B', 'C'] as $i => $name) {
        $stop = Stop::create(['stop_name' => "Re Stop $name", 'latitude' => 7.0 + $i / 10, 'longitude' => 125.0, 'company_id' => $company->company_id]);
        $routeStops[$name] = RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => $i + 1, 'distance_from_origin_km' => $i * 10, 'stop_type' => 'stop']);
    }

    return [$route, $routeStops];
}

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'Asia/Manila'));
});
afterEach(fn () => Carbon::setTestNow());

it('lets the Company Admin rename a route and change its origin and destination', function () {
    $company = Company::create(['company_name' => 'Re Co']);
    [$route] = reRoute($company);

    $this->actingAs(reOperator($company), 'sanctum')
        ->patchJson("/api/operator/routes/{$route->route_id}", ['route_name' => '  New Name ', 'destination' => 'C Terminal'])
        ->assertOk()->assertJsonPath('data.route_name', 'New Name');

    expect($route->fresh()->only(['route_name', 'origin', 'destination']))->toBe(['route_name' => 'New Name', 'origin' => 'A', 'destination' => 'C Terminal']);
});

it('changes the stop order when every stop is listed once', function () {
    $company = Company::create(['company_name' => 'Re Co']);
    [$route, $stops] = reRoute($company);
    $this->actingAs(reOperator($company), 'sanctum');

    $this->putJson("/api/operator/routes/{$route->route_id}/stops/order", ['route_stop_ids' => [$stops['A']->id, $stops['C']->id, $stops['B']->id]])
        ->assertOk();

    expect(RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->pluck('id')->all())->toBe([$stops['A']->id, $stops['C']->id, $stops['B']->id]);

    // A list that misses a stop, or names another route's stop, is refused.
    $this->putJson("/api/operator/routes/{$route->route_id}/stops/order", ['route_stop_ids' => [$stops['A']->id, $stops['B']->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('route_stop_ids');
});

it('refuses a new stop order while an upcoming trip of the route has tickets sold', function () {
    $company = Company::create(['company_name' => 'Re Co']);
    [$route, $stops] = reRoute($company);
    $admin = reOperator($company);
    $fleet = Fleet::create(['company_user_id' => $admin->companyProfile->company_user_id, 'company_id' => $company->company_id, 'plate_number' => 'RE-1', 'capacity' => 30, 'seated_capacity' => 30, 'standing_capacity' => 0, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $admin->companyProfile->company_user_id, 'trip_date' => '2026-10-06', 'departure_time' => '08:00', 'trip_type' => 'one_way', 'leg_direction' => 'outbound', 'status' => 'scheduled', 'current_seated_capacity' => 1, 'current_standing_capacity' => 0, 'total_occupancy' => 1]);
    $payment = Payment::create(['amount' => 50, 'payment_created' => now(), 'transaction_reference' => 'TXN-RE-'.Str::random(6), 'payment_method' => 'online', 'payment_channel' => 'gcash', 'status' => 'paid', 'payment_uuid' => (string) Str::uuid(), 'is_valid' => true, 'items_payload' => []]);
    Ticket::create(['ticket_uuid' => (string) Str::uuid(), 'fleet_route_id' => $fleetRoute->fleet_route_id, 'trip_id' => $trip->trip_id, 'payment_id' => $payment->payment_id, 'status' => 'valid', 'amount' => 50, 'seat_type' => 'seated', 'origin_stop_id' => $stops['A']->stop_id, 'destination_stop_id' => $stops['C']->stop_id, 'distance_km' => 20]);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/operator/routes/{$route->route_id}/stops/order", ['route_stop_ids' => [$stops['C']->id, $stops['B']->id, $stops['A']->id]])
        ->assertUnprocessable()
        ->assertJsonFragment(['1 upcoming trip of this route already has tickets sold, so its stop order cannot change now. Change it after those trips run or are cancelled.']);

    expect(RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->pluck('id')->all())->toBe([$stops['A']->id, $stops['B']->id, $stops['C']->id]);
});

it('lets only the Company Admin of the route\'s company edit it', function () {
    $company = Company::create(['company_name' => 'Re Co']);
    $other = Company::create(['company_name' => 'Other Co']);
    [$route, $stops] = reRoute($company);

    $this->actingAs(reOperator($company, false), 'sanctum')
        ->patchJson("/api/operator/routes/{$route->route_id}", ['route_name' => 'X'])->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->actingAs(reOperator($other), 'sanctum')
        ->patchJson("/api/operator/routes/{$route->route_id}", ['route_name' => 'X'])->assertNotFound();
    $this->putJson("/api/operator/routes/{$route->route_id}/stops/order", ['route_stop_ids' => [$stops['C']->id, $stops['B']->id, $stops['A']->id]])->assertNotFound();

    expect($route->fresh()->route_name)->toBe('Old Name');
});
