<?php

namespace Database\Seeders;

use App\Models\FareRule;
use App\Models\Fleet;
use Illuminate\Database\Seeder;

/**
 * DEV ONLY (Batch 28, Stage 2, item 5): sample fleet classes and sample general fare rules so the fare key can be
 * tried on the dev stack. Every rule is labelled SAMPLE in its note and every number below is an outdated sample
 * value, not a real fare. Not part of DatabaseSeeder: run it by hand with
 *   php artisan db:seed --class=SampleFareKeySeeder
 * It never touches an existing rule, and sets a class only on fleets that have none.
 */
class SampleFareKeySeeder extends Seeder
{
    /** class => [service type => [base, per km]] ; 5 km base distance for all. */
    private const SAMPLE_RULES = [
        'ordinary' => ['regular' => [11.00, 1.90]],
        'aircon' => ['regular' => [13.00, 2.25], 'express' => [18.00, 2.75]],
    ];

    public function run(): void
    {
        $classes = array_keys(self::SAMPLE_RULES);
        $unclassified = Fleet::whereNull('vehicle_class')->orderBy('fleet_id')->get();
        foreach ($unclassified as $i => $fleet) {
            $fleet->update(['vehicle_class' => $classes[$i % count($classes)]]);
        }

        foreach (self::SAMPLE_RULES as $class => $services) {
            foreach ($services as $service => [$base, $perKm]) {
                foreach (['seated', 'standing'] as $seat) {
                    FareRule::firstOrCreate(
                        ['fleet_id' => null, 'route_id' => null, 'vehicle_class' => $class, 'service_type' => $service, 'seat_type' => $seat, 'status' => 'active'],
                        ['base_fare' => $base, 'fare_per_km' => $perKm, 'base_distance_km' => 5, 'rounding_mode' => 'nearest', 'note' => 'SAMPLE - dev only, not a real fare'],
                    );
                }
            }
        }
    }
}
