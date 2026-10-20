<?php

use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\User;
use App\Repositories\RouteGeometryRepository;
use App\Services\RouteGeometryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function regionTestOperator(): User
{
    $user = User::create([
        'username' => fake()->unique()->userName(),
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);

    StaffUser::create([
        'user_id' => $user->user_id,
        'company_user_uuid' => (string) Str::uuid(),
        'name' => fake()->name(),
        'phone_num' => fake()->phoneNumber(),
        'address' => fake()->address(),
    ]);

    return $user->fresh();
}

beforeEach(function () {
    // Keep the off-network check out of these tests (no provider call).
    config(['route_geometry.ors.api_key' => null]);
});

it('serves the region from the single region config', function () {
    $this->getJson('/api/region')
        ->assertSuccessful()
        ->assertJsonPath('data.name', config('region.name'))
        ->assertJsonPath('data.center.lat', (float) config('region.center.lat'))
        ->assertJsonPath('data.bbox.max_lat', (float) config('region.bbox.max_lat'))
        ->assertJsonStructure(['data' => ['provinces']]);
});

it('changes region behaviour by config only, with no code change', function () {
    $operator = regionTestOperator();
    $inside = ['stop_name' => 'Config Probe', 'latitude' => 7.0, 'longitude' => 126.2];

    // Outside a different configured region: rejected.
    config(['region.bbox' => ['min_lat' => 10.0, 'max_lat' => 11.0, 'min_lng' => 122.0, 'max_lng' => 123.0]]);
    $this->actingAs($operator)->postJson('/api/operator/stops', $inside)->assertStatus(422);

    // Same point once the region config covers it: accepted.
    config(['region.bbox' => ['min_lat' => 5.3, 'max_lat' => 8.1, 'min_lng' => 124.9, 'max_lng' => 126.7]]);
    $this->actingAs($operator)->postJson('/api/operator/stops', $inside)->assertSuccessful();

    // The region view follows the config too.
    config(['region.name' => 'Another Region', 'region.center' => ['lat' => 10.5, 'lng' => 122.5]]);
    $this->getJson('/api/region')
        ->assertJsonPath('data.name', 'Another Region')
        ->assertJsonPath('data.center.lat', 10.5);
});

it('works for a second, different route with no code change (dummy route proof)', function () {
    $operator = regionTestOperator();

    // A route in other municipalities and another province than the seeded corridor.
    $route = Route::create(['route_name' => 'Dummy East-West', 'origin' => 'East end', 'destination' => 'West end']);
    $points = [
        ['Dummy Terminal East', 6.9553, 126.2167, 0, 'mati city'],   // typed lower-case on purpose
        ['Dummy Junction', 6.90, 125.85, 40, 'Mabini'],
        ['Dummy Terminal West', 6.7500, 125.3570, 95, 'Digos City'],
    ];

    foreach ($points as $i => [$name, $lat, $lng, $km, $municipality]) {
        $response = $this->actingAs($operator)->postJson('/api/operator/stops', [
            'stop_name' => $name,
            'latitude' => $lat,
            'longitude' => $lng,
            'municipality' => $municipality,
        ])->assertSuccessful();

        RouteStop::create([
            'route_id' => $route->route_id,
            'stop_id' => $response->json('data.stop_id'),
            'stop_order' => $i + 1,
            'distance_from_origin_km' => $km,
        ]);
    }

    // Municipalities are stored in their canonical spelling and served with the stops.
    $this->getJson("/api/routes/{$route->route_id}/stops")
        ->assertSuccessful()
        ->assertJsonPath('data.0.municipality', 'Mati City')
        ->assertJsonPath('data.1.municipality', 'Mabini')
        ->assertJsonPath('data.2.municipality', 'Digos City');

    // Geometry generation is data-driven as well.
    config(['route_geometry.ors.api_key' => 'test-key']);
    Http::fake([
        '*/v2/snap/*' => Http::response(['locations' => [
            ['location' => [126.2168, 6.9554]], ['location' => [125.85, 6.90]], ['location' => [125.3571, 6.7501]],
        ]]),
        '*/v2/directions/*' => Http::response(['features' => [[
            'properties' => ['segments' => [['distance' => 40000.0, 'duration' => 2400.0], ['distance' => 55000.0, 'duration' => 3300.0]]],
            'geometry' => ['coordinates' => [
                [126.2168, 6.9554], [125.85, 6.90], [125.3571, 6.7501],
            ]],
        ]]]),
    ]);

    expect(app(RouteGeometryGenerator::class)->generate($route->route_id)['ok'])->toBeTrue();
    expect(app(RouteGeometryRepository::class)->get($route->route_id)['coordinates'])->toHaveCount(3);
});

it('rejects a municipality that is not in the region config', function () {
    $this->actingAs(regionTestOperator())->postJson('/api/operator/stops', [
        'stop_name' => 'Nowhere Stop',
        'latitude' => 7.0,
        'longitude' => 125.6,
        'municipality' => 'Atlantis',
    ])->assertStatus(422)->assertJsonValidationErrors(['municipality']);
});

it('keeps place names out of application code; only config/region.php holds them', function () {
    $literal = '/\b(Tagum|Ecoland|Toril|Panabo|Bankerohan|Davao)\b/i';
    $offenders = [];

    foreach (['app', 'routes', 'config'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with(str_replace('\\', '/', $file->getPathname()), 'config/region.php')) {
                continue;
            }
            if (preg_match($literal, file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});
