<?php

use App\Models\Company;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// 2026-11-06 (Batch 47 part A): a company admin pins a stop on the map; the place search (Photon while typing,
// Nominatim on Search) only helps find it. A stop has a typed location label; a move waits for running trips.

function spAdmin(): User
{
    $company = Company::firstOrCreate(['company_name' => 'Davao Metro Shuttle']);
    $user = User::create(['username' => 'sp'.Str::random(6), 'email' => 'sp.'.Str::lower(Str::random(6)).'@example.com', 'password' => 'Right-password-1', 'role' => 'operator']);
    StaffUser::create(['user_id' => $user->user_id, 'company_user_uuid' => (string) Str::uuid(), 'name' => 'Sp Admin', 'phone_num' => '+639171234567', 'address' => 'Tagum', 'company_id' => $company->company_id, 'is_company_admin' => true]);

    return $user->fresh();
}

function spPhotonFeature(string $name, float $lat, float $lng, array $props = []): array
{
    return ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]], 'properties' => array_merge(['name' => $name], $props)];
}

it('suggests places while typing with a detail line that tells same names apart, inside the region only', function () {
    Http::fake([
        'photon.komoot.io/*' => Http::response(['features' => [
            spPhotonFeature('Acacia', 7.12, 125.61, ['street' => 'Indangan Road', 'district' => 'Indangan', 'city' => 'Sample City']),
            spPhotonFeature('Acacia', 7.07, 125.60, ['district' => 'Poblacion', 'city' => 'Sample City']),
            spPhotonFeature('Acacia', 14.6, 121.0, ['city' => 'Far Away']),
        ]]),
    ]);

    $this->actingAs(spAdmin(), 'sanctum');
    $data = $this->getJson('/api/operator/places?q=Acacia')->assertSuccessful()->json('data');

    expect($data['source'])->toBe('photon')
        ->and(collect($data['results'])->pluck('detail')->all())->toBe(['Indangan Road, Indangan, Sample City', 'Poblacion, Sample City']);
    expect($this->getJson('/api/operator/places?q=Ac')->json('data.results'))->toBe([]);
});

it('falls back to Nominatim when Photon fails, and uses Nominatim for the Search button', function () {
    Http::fake([
        'photon.komoot.io/*' => Http::response('down', 503),
        'nominatim.openstreetmap.org/*' => Http::response([['display_name' => 'Acacia, Poblacion, Sample City, 8000, Philippines', 'lat' => '7.07', 'lon' => '125.60']]),
    ]);

    $this->actingAs(spAdmin(), 'sanctum');
    $suggest = $this->getJson('/api/operator/places?q=Acacia')->json('data');
    $search = $this->getJson('/api/operator/places?q=Acacia%20street&mode=search')->json('data');

    expect($suggest['source'])->toBe('nominatim')
        ->and($suggest['results'][0])->toMatchArray(['name' => 'Acacia', 'detail' => 'Poblacion, Sample City, Philippines'])
        ->and($search['source'])->toBe('nominatim');
});

it('needs a pin for a new stop, keeps the typed label, and marks it pinned with a warning for a stop very close by', function () {
    $admin = spAdmin();
    $this->actingAs($admin, 'sanctum');

    $this->postJson('/api/operator/stops', ['stop_name' => 'Acacia', 'location_label' => 'Downtown / Poblacion'])
        ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);

    $first = $this->postJson('/api/operator/stops', ['stop_name' => 'Acacia', 'location_label' => '  Downtown /  Poblacion ', 'latitude' => 7.0700, 'longitude' => 125.6000])
        ->assertSuccessful()->json('data');
    $near = $this->postJson('/api/operator/stops', ['stop_name' => 'Acacia Corner', 'latitude' => 7.07010, 'longitude' => 125.60010])
        ->assertSuccessful()->json('data');

    expect($first)->toMatchArray(['location_label' => 'Downtown / Poblacion', 'location_source' => 'pinned'])
        ->and($first['warnings'])->toBe([])
        ->and($near['warnings'][0])->toContain('"Acacia" is only')
        ->and(DB::table('audit_logs')->where('action', 'stop.create')->count())->toBe(2);
});

it('refuses to move a stop while a trip on its route is running, and moves it otherwise, recording where from and to', function () {
    $admin = spAdmin();
    $companyId = $admin->companyProfile->company_id;
    $stop = Stop::create(['stop_name' => 'Old Place', 'latitude' => 7.07, 'longitude' => 125.6, 'company_id' => $companyId, 'location_source' => 'name']);
    $route = Route::create(['route_name' => 'Sp Route', 'origin' => 'A', 'destination' => 'B', 'company_id' => $companyId]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $stop->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0, 'stop_type' => 'terminal']);
    $fleet = Fleet::create(['company_user_id' => $admin->companyProfile->company_user_id, 'company_id' => $companyId, 'plate_number' => 'SP-001', 'capacity' => 50, 'seated_capacity' => 40, 'standing_capacity' => 10, 'status' => 'active']);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '05:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create(['fleet_route_id' => $fleetRoute->fleet_route_id, 'company_user_id' => $admin->companyProfile->company_user_id, 'trip_date' => now()->toDateString(), 'status' => 'boarding', 'current_seated_capacity' => 0, 'current_standing_capacity' => 0, 'total_occupancy' => 0]);

    $this->actingAs($admin, 'sanctum');
    $this->putJson("/api/operator/stops/{$stop->stop_id}", ['latitude' => 7.08, 'longitude' => 125.61])
        ->assertUnprocessable()->assertJsonPath('errors.latitude.0', 'A trip on a route with this stop is running now. Move the stop after the trip ends.');
    $this->putJson("/api/operator/stops/{$stop->stop_id}", ['location_label' => 'Near the market'])->assertSuccessful();

    $trip->update(['status' => 'completed']);
    $this->putJson("/api/operator/stops/{$stop->stop_id}", ['latitude' => 7.08, 'longitude' => 125.61])->assertSuccessful();

    $fresh = $stop->fresh();
    $move = json_decode((string) DB::table('audit_logs')->where('action', 'stop.move')->value('context'), true);
    expect((float) $fresh->latitude)->toBe(7.08)
        ->and($fresh->location_source)->toBe('pinned')
        ->and($fresh->pinned_at)->not->toBeNull()
        ->and($fresh->location_label)->toBe('Near the market')
        ->and((float) $move['from']['latitude'])->toBe(7.07)
        ->and((float) $move['to']['latitude'])->toBe(7.08);
});

it('warns when a stop is well off the way between its neighbours on a route, when added or moved', function () {
    $admin = spAdmin();
    $companyId = $admin->companyProfile->company_id;
    $route = Route::create(['route_name' => 'Sp Line', 'origin' => 'A', 'destination' => 'B', 'company_id' => $companyId]);
    $first = Stop::create(['stop_name' => 'First', 'latitude' => 7.00, 'longitude' => 125.60, 'company_id' => $companyId]);
    $last = Stop::create(['stop_name' => 'Last', 'latitude' => 7.10, 'longitude' => 125.60, 'company_id' => $companyId]);
    $between = Stop::create(['stop_name' => 'Between', 'latitude' => 7.05, 'longitude' => 125.601, 'company_id' => $companyId]);
    $offWay = Stop::create(['stop_name' => 'Off the way', 'latitude' => 7.05, 'longitude' => 125.75, 'company_id' => $companyId]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $first->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0, 'stop_type' => 'terminal']);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $last->stop_id, 'stop_order' => 3, 'distance_from_origin_km' => 11, 'stop_type' => 'terminal']);

    $this->actingAs($admin, 'sanctum');
    $onTheWay = $this->postJson("/api/operator/routes/{$route->route_id}/stops", ['stop_id' => $between->stop_id, 'stop_order' => 2])->assertSuccessful()->json('data');
    expect($onTheWay['warnings'])->toBe([]);

    $this->putJson("/api/operator/stops/{$between->stop_id}", ['latitude' => 7.05, 'longitude' => 125.70])
        ->assertSuccessful()
        ->assertJsonPath('data.warnings.0', fn (string $text) => str_starts_with($text, 'On Sp Line, this stop adds about') && str_contains($text, 'between "First" and "Last"'));

    // A last stop has one neighbour and is not checked; the off-the-way stop added at the end gets no warning.
    $atEnd = $this->postJson("/api/operator/routes/{$route->route_id}/stops", ['stop_id' => $offWay->stop_id, 'stop_order' => 4])->assertSuccessful()->json('data');
    expect($atEnd['warnings'])->toBe([]);
});
