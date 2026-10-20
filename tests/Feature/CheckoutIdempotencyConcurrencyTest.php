<?php

use App\Models\FareRule;
use App\Models\Fleet;
use App\Models\FleetRoute;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\RewardTransaction;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\StaffUser;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('creates only one online payment, hold, reward redemption, and gateway session for simultaneous identical requests', function () {
    if (getenv('CHECKOUT_CONCURRENCY_TESTS') !== '1') {
        $this->markTestSkipped('Set CHECKOUT_CONCURRENCY_TESTS=1 only for an isolated, pre-migrated PostgreSQL test database.');
    }
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('This cross-process concurrency test requires PostgreSQL.');
    }
    if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'idempotency_key_hash')) {
        $this->markTestSkipped('Run migrations on the isolated PostgreSQL test database before this concurrency test.');
    }

    if (! function_exists('proc_open')) {
        $this->markTestSkipped('This process-based concurrency test requires proc_open.');
    }

    $operator = User::create([
        'username' => 'idem_operator_'.Str::random(8),
        'email' => 'idem_operator_'.Str::random(8).'@example.test',
        'password' => bcrypt('password123'),
        'role' => 'operator',
    ]);
    StaffUser::create([
        'company_user_uuid' => (string) Str::uuid(),
        'user_id' => $operator->user_id,
        'name' => 'Idempotency Operator',
        'phone_num' => '09170000001',
        'address' => 'Test',
    ]);
    $passengerUser = User::create([
        'username' => 'idem_passenger_'.Str::random(8),
        'email' => 'idem_passenger_'.Str::random(8).'@example.test',
        'password' => bcrypt('password123'),
        'role' => 'passenger',
    ]);
    $passenger = PassengerUser::create([
        'passenger_uuid' => (string) Str::uuid(),
        'user_id' => $passengerUser->user_id,
        'name' => 'Concurrent Passenger',
        'phone_num' => '09170000002',
        'address' => 'Test',
        'birthdate' => '2000-01-01',
        'reward_points' => 20,
    ]);

    $route = Route::create(['route_name' => 'Idempotency Route', 'origin' => 'A', 'destination' => 'B']);
    $origin = Stop::create(['stop_name' => 'Idempotency A', 'latitude' => 7.0, 'longitude' => 125.0]);
    $destination = Stop::create(['stop_name' => 'Idempotency B', 'latitude' => 7.1, 'longitude' => 125.0]);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $origin->stop_id, 'stop_order' => 1, 'distance_from_origin_km' => 0, 'stop_type' => 'terminal']);
    RouteStop::create(['route_id' => $route->route_id, 'stop_id' => $destination->stop_id, 'stop_order' => 2, 'distance_from_origin_km' => 10, 'stop_type' => 'terminal']);
    $fleet = Fleet::create([
        'company_user_id' => $operator->companyProfile->company_user_id,
        'plate_number' => 'IDEM-'.Str::upper(Str::random(5)),
        'capacity' => 1, 'seated_capacity' => 1, 'standing_capacity' => 0, 'status' => 'active', 'vehicle_class' => 'aircon',
    ]);
    $fleetRoute = FleetRoute::create(['fleet_id' => $fleet->fleet_id, 'route_id' => $route->route_id, 'start_time' => '06:00', 'end_time' => '22:00', 'status' => 'active']);
    $trip = Trip::create([
        'fleet_route_id' => $fleetRoute->fleet_route_id,
        'company_user_id' => $operator->companyProfile->company_user_id,
        'trip_date' => now('Asia/Manila')->addDay()->toDateString(),
        'departure_time' => '08:00',
        'trip_type' => 'one_way',
        'status' => 'scheduled',
        'current_seated_capacity' => 0,
        'current_standing_capacity' => 0,
        'total_occupancy' => 0,
    ]);
    FareRule::create([
        'fleet_id' => null, 'vehicle_class' => 'aircon', 'service_type' => 'regular', 'seat_type' => 'seated',
        'status' => 'active', 'base_fare' => 13, 'fare_per_km' => 2.25, 'base_distance_km' => 5,
    ]);

    $key = 'parallel-idempotency-'.Str::random(20);
    $payload = [
        'booking_date' => $trip->trip_date->toDateString(),
        'payment_channel' => 'gcash',
        'reward_points_to_use' => 5,
        'items' => [[
            'trip_id' => $trip->trip_id,
            'seat_type' => 'seated',
            'origin_stop_id' => $origin->stop_id,
            'destination_stop_id' => $destination->stop_id,
        ]],
    ];

    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smart-transit-idem-'.Str::random(12);
    if (! mkdir($barrier) && ! is_dir($barrier)) {
        throw new RuntimeException('Could not create concurrency test barrier.');
    }
    $goPath = $barrier.DIRECTORY_SEPARATOR.'go';
    $processes = [];

    try {
        for ($index = 0; $index < 2; $index++) {
            $spec = [
                'ready_path' => $barrier.DIRECTORY_SEPARATOR.'ready-'.$index,
                'go_path' => $goPath,
                'passenger_id' => $passenger->passenger_id,
                'idempotency_key' => $key,
                'payload' => $payload,
            ];
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/checkout_idempotency_worker.php')], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start a checkout concurrency worker.');
            }
            fwrite($pipes[0], json_encode($spec, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'pipes' => $pipes, 'ready_path' => $spec['ready_path']];
        }

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if (count(array_filter($processes, fn ($worker) => is_file($worker['ready_path']))) === 2) {
                break;
            }
            usleep(10_000);
        }
        expect(array_filter($processes, fn ($worker) => is_file($worker['ready_path'])))->toHaveCount(2);
        file_put_contents($goPath, 'go');

        $results = [];
        foreach ($processes as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            expect($exitCode, $stderr)->toBe(0);
            $results[] = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($processes as $worker) {
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
        foreach (glob($barrier.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($barrier);
    }

    expect(collect($results)->pluck('outcome')->every(fn ($outcome) => in_array($outcome, ['success', 'conflict'], true)))->toBeTrue();
    expect(collect($results)->sum('provider_calls'))->toBe(1);
    expect(Payment::where('idempotency_scope_hash', hash('sha256', 'passenger:'.$passenger->passenger_id))
        ->where('idempotency_key_hash', hash('sha256', $key))->count())->toBe(1);
    expect((int) $trip->fresh()->current_seated_capacity)->toBe(1);
    expect((int) $passenger->fresh()->reward_points)->toBe(15);
    expect(RewardTransaction::where('passenger_id', $passenger->passenger_id)->where('type', 'redeemed')->count())->toBe(1);
});
