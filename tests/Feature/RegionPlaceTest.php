<?php

use App\Services\ReverseGeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// Batch 51 item 3 (user, 2026-11-09): "Are you in {place}?" on the homepage. The point is rounded to about 1 km, the
// answer is a town of the region list or nothing, and the lookup is cached. No outside calls: the geocoder is faked.

function rpPlaces(array $names): object
{
    $fake = Mockery::mock(ReverseGeocodingService::class);
    $fake->shouldReceive('townNames')->andReturn($names);
    app()->instance(ReverseGeocodingService::class, $fake);

    return $fake;
}

beforeEach(fn () => Cache::flush());

it('names the region town a point is in, matching "City" spellings', function () {
    $town = collect(config('region.provinces'))->flatten()->first(fn ($name) => str_ends_with($name, ' City'));
    $province = collect(config('region.provinces'))->search(fn ($towns) => in_array($town, $towns, true));
    rpPlaces([preg_replace('/ City$/', '', $town)]);
    $center = config('region.center');

    $this->getJson("/api/region/place?lat={$center['lat']}&lng={$center['lng']}")
        ->assertOk()
        ->assertJsonPath('data.place', $town)
        ->assertJsonPath('data.province', $province);
});

it('gives nothing for a name the region does not have, or a point outside the region', function () {
    rpPlaces(['Not A Region Town']);
    $center = config('region.center');
    $this->getJson("/api/region/place?lat={$center['lat']}&lng={$center['lng']}")->assertOk()->assertJsonPath('data', null);

    $box = config('region.bbox');
    $this->getJson('/api/region/place?lat='.($box['max_lat'] + 1).'&lng='.$box['max_lng'])->assertOk()->assertJsonPath('data', null);
});

it('asks the geocoder once per rounded point', function () {
    $town = collect(config('region.provinces'))->flatten()->first();
    $fake = Mockery::mock(ReverseGeocodingService::class);
    $fake->shouldReceive('townNames')->once()->andReturn([$town]);
    app()->instance(ReverseGeocodingService::class, $fake);
    $lat = round((float) config('region.center.lat'), 2);
    $lng = round((float) config('region.center.lng'), 2);

    // Two points about 100 m apart: the same point once rounded to 2 decimals, so one lookup.
    $this->getJson('/api/region/place?lat='.($lat + 0.001).'&lng='.($lng + 0.001))->assertOk()->assertJsonPath('data.place', $town);
    $this->getJson('/api/region/place?lat='.($lat + 0.002).'&lng='.($lng + 0.002))->assertOk()->assertJsonPath('data.place', $town);
});

it('lists stop positions for the nearest-stop search on the device', function () {
    $this->getJson('/api/booking/stops')->assertOk()->assertJsonStructure(['data' => ['groups']]);
});
