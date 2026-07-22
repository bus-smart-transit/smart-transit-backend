<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\StaffUser;
use App\Models\Fleet;
use App\Models\Route;
use App\Models\Stop;
use App\Models\RouteStop;
use App\Models\FleetRoute;
use Illuminate\Support\Facades\Hash;

class PinTestingSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user (role: admin) - already exists in Supabase
        $admin = User::firstOrCreate(
            ['email' => 'admin@busmartransit.test'],
            [
                'username' => 'admin',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ]
        );

        // Operator user (role: operator) - matches existing Supabase account
        $operator = User::firstOrCreate(
            ['email' => 'operator@smarttransit.com'],
            [
                'username' => 'operator',
                'password' => Hash::make('password123'),
                'role' => 'operator',
            ]
        );

        $operatorStaff = StaffUser::firstOrCreate(
            ['user_id' => $operator->id],
            [
                'phone_num' => '+639123456789',
                'name' => 'John Operator',
                'address' => 'Manila, Philippines',
                'birth_date' => now()->subYears(35),
            ]
        );

        // Driver user (role: driver) - matches existing Supabase account
        $driver = User::firstOrCreate(
            ['email' => 'driver@smarttransit.com'],
            [
                'username' => 'driver',
                'password' => Hash::make('password123'),
                'role' => 'driver',
            ]
        );

        $driverStaff = StaffUser::firstOrCreate(
            ['user_id' => $driver->id],
            [
                'phone_num' => '+639187654321',
                'name' => 'Juan Driver',
                'address' => 'Makati, Philippines',
                'birth_date' => now()->subYears(32),
            ]
        );

        // Conductor user (role: conductor) - matches existing Supabase account
        $conductor = User::firstOrCreate(
            ['email' => 'conductor@smarttransit.com'],
            [
                'username' => 'conductor',
                'password' => Hash::make('password123'),
                'role' => 'conductor',
            ]
        );

        $conductorStaff = StaffUser::firstOrCreate(
            ['user_id' => $conductor->id],
            [
                'phone_num' => '+639111223344',
                'name' => 'Maria Conductor',
                'address' => 'Quezon City, Philippines',
                'birth_date' => now()->subYears(28),
            ]
        );

        // Create stops
        $stop1 = Stop::create([
            'stop_name' => 'Central Station',
            'longitude' => 120.9842,
            'latitude' => 14.5995,
        ]);

        $stop2 = Stop::create([
            'stop_name' => 'Makati CBD',
            'longitude' => 121.0188,
            'latitude' => 14.5549,
        ]);

        $stop3 = Stop::create([
            'stop_name' => 'BGC Station',
            'longitude' => 121.0403,
            'latitude' => 14.5590,
        ]);

        // Create route
        $route = Route::create([
            'origin' => 'Central Station',
            'destination' => 'BGC Station',
            'route_name' => 'Route 1: Manila to BGC',
        ]);

        // Add stops to route
        RouteStop::create([
            'route_id' => $route->route_id,
            'stop_id' => $stop1->stop_id,
            'stop_order' => 1,
            'distance_from_origin_km' => 0,
        ]);

        RouteStop::create([
            'route_id' => $route->route_id,
            'stop_id' => $stop2->stop_id,
            'stop_order' => 2,
            'distance_from_origin_km' => 5.5,
        ]);

        RouteStop::create([
            'route_id' => $route->route_id,
            'stop_id' => $stop3->stop_id,
            'stop_order' => 3,
            'distance_from_origin_km' => 11.2,
        ]);

        // Create fleet
        $fleet = Fleet::create([
            'company_user_id' => $operatorStaff->company_user_id,
            'plate_number' => 'HLH-2024-001',
            'capacity' => 50,
            'seated_capacity' => 30,
            'standing_capacity' => 20,
            'status' => 'active',
            'fleet_type' => 'public',
        ]);

        // Assign route to fleet
        $fleetRoute = FleetRoute::create([
            'fleet_id' => $fleet->fleet_id,
            'route_id' => $route->route_id,
            'start_time' => '06:00',
            'end_time' => '22:00',
            'status' => 'active',
        ]);

        // Find or use an existing trip with tickets on today's date
        $existingTrip = \App\Models\Trip::with(['fleetRoute.route', 'fleetRoute.fleet', 'tickets'])
            ->where('trip_date', now()->toDateString())
            ->has('tickets')  // Only trips that have tickets
            ->first();

        if ($existingTrip) {
            // Use existing trip with tickets - just assign driver and conductor
            $existingTrip->update([
                'driver_id' => $driverStaff->company_user_id,
                'conductor_id' => $conductorStaff->company_user_id,
                'status' => 'boarding',  // Ensure status is active
            ]);
            $trip = $existingTrip;
            echo "\nUsing existing trip ID: {$trip->trip_id} with " . $trip->tickets->count() . " tickets\n";
        } else {
            // Fallback: Create new trip (only if no existing trips found)
            $trip = \App\Models\Trip::create([
                'fleet_route_id' => $fleetRoute->fleet_route_id,
                'company_user_id' => $operatorStaff->company_user_id,
                'driver_id' => $driverStaff->company_user_id,
                'conductor_id' => $conductorStaff->company_user_id,
                'trip_date' => now()->toDateString(),
                'status' => 'boarding',
                'current_seated_capacity' => 0,
                'current_standing_capacity' => 0,
                'total_occupancy' => 0,
            ]);
            echo "\nCreated new trip ID: {$trip->trip_id}\n";
        }

        // If we had to create a new trip, add test tickets
        if (!$existingTrip) {
            // Create test passengers
            $passenger1 = \App\Models\PassengerUser::create([
                'user_id' => \App\Models\User::create([
                    'email' => 'passenger1@test.com',
                    'username' => 'passenger1',
                    'password' => Hash::make('password123'),
                    'role' => 'passenger',
                ])->id,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone_number' => '09123456789',
            ]);

            $passenger2 = \App\Models\PassengerUser::create([
                'user_id' => \App\Models\User::create([
                    'email' => 'passenger2@test.com',
                    'username' => 'passenger2',
                    'password' => Hash::make('password123'),
                    'role' => 'passenger',
                ])->id,
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'phone_number' => '09987654321',
            ]);

            // Create payments for test passengers
            $payment1 = \App\Models\OnlinePayment::create([
                'reference_code' => 'REF-' . uniqid(),
                'amount_paid' => 150,
                'status' => 'paid',
                'payment_method' => 'gcash',
            ]);

            $payment2 = \App\Models\OnlinePayment::create([
                'reference_code' => 'REF-' . uniqid(),
                'amount_paid' => 150,
                'status' => 'paid',
                'payment_method' => 'card',
            ]);

            // Create test tickets
            \App\Models\Ticket::create([
                'fleet_route_id' => $fleetRoute->fleet_route_id,
                'trip_id' => $trip->trip_id,
                'passenger_id' => $passenger1->passenger_id,
                'payment_id' => $payment1->payment_id,
                'ticket_uuid' => \Illuminate\Support\Str::uuid(),
                'status' => 'boarded',
                'amount' => 150,
                'seat_type' => 'seated',
                'origin_stop_id' => $stop1->stop_id,
                'destination_stop_id' => $stop3->stop_id,
                'distance_km' => 11.2,
                'boarded_at' => now(),
            ]);

            \App\Models\Ticket::create([
                'fleet_route_id' => $fleetRoute->fleet_route_id,
                'trip_id' => $trip->trip_id,
                'passenger_id' => $passenger2->passenger_id,
                'payment_id' => $payment2->payment_id,
                'ticket_uuid' => \Illuminate\Support\Str::uuid(),
                'status' => 'boarded',
                'amount' => 150,
                'seat_type' => 'standing',
                'origin_stop_id' => $stop1->stop_id,
                'destination_stop_id' => $stop2->stop_id,
                'distance_km' => 5.5,
                'boarded_at' => now(),
            ]);
        }

        // Output credentials and IDs for testing
        echo "\n\n========== PIN SYSTEM TEST DATA ==========\n\n";
        
        echo "ADMIN LOGIN:\n";
        echo "  Email: admin@busmartransit.test\n";
        echo "  Password: password123\n\n";

        echo "OPERATOR LOGIN:\n";
        echo "  Email: operator@smarttransit.com\n";
        echo "  Password: password123\n";
        echo "  Company User ID: {$operatorStaff->company_user_id}\n\n";

        echo "DRIVER LOGIN:\n";
        echo "  Email: driver@smarttransit.com\n";
        echo "  Password: password123\n";
        echo "  Company User ID: {$driverStaff->company_user_id}\n\n";

        echo "CONDUCTOR LOGIN:\n";
        echo "  Email: conductor@smarttransit.com\n";
        echo "  Password: password123\n";
        echo "  Company User ID: {$conductorStaff->company_user_id}\n\n";

        echo "FLEET & TRIP DATA:\n";
        echo "  Fleet ID: {$fleet->fleet_id}\n";
        echo "  Fleet Route ID: {$fleetRoute->fleet_route_id}\n";
        echo "  Trip ID: {$trip->trip_id}\n";
        echo "  Trip Date: {$trip->trip_date}\n";
        echo "  Trip Status: {$trip->status}\n";
        echo "  Driver ID: {$driverStaff->company_user_id}\n";
        echo "  Conductor ID: {$conductorStaff->company_user_id}\n";
        echo "  Total Passengers on Trip: {$trip->tickets->count()}\n\n";

        echo "STOP DATA:\n";
        echo "  Stop 1 ID: {$stop1->stop_id} - {$stop1->stop_name}\n";
        echo "  Stop 2 ID: {$stop2->stop_id} - {$stop2->stop_name}\n";
        echo "  Stop 3 ID: {$stop3->stop_id} - {$stop3->stop_name}\n\n";

        if ($trip->tickets->count() > 0) {
            echo "TICKET DATA (First few tickets on this trip):\n";
            $trip->tickets->take(3)->each(function ($ticket, $index) {
                $ticketNumber = $index + 1;
                echo "  Ticket {$ticketNumber}:\n";
                echo "    - ID: {$ticket->ticket_id}\n";
                echo "    - UUID: {$ticket->ticket_uuid}\n";
                echo "    - Status: {$ticket->status}\n";
                if ($ticket->passenger) {
                    echo "    - Passenger: {$ticket->passenger->first_name} {$ticket->passenger->last_name}\n";
                }
                echo "\n";
            });
        }

        echo "=========================================\n\n";
    }
}
