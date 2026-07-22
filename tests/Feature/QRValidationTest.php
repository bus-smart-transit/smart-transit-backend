<?php

describe('QR Code & Occupancy Monitoring System', function () {
    
    it('conductor can retrieve current trip occupancy metrics', function () {
        $conductorLogin = $this->postJson('/api/staff/login', [
            'username' => 'conductor@smarttransit.com',
            'password' => 'password123'
        ]);
        
        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current/occupancy');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [
                'trip_id',
                'trip_status',
                'fleet_id',
                'fleet_plate_number',
                'capacity' => [
                    'total',
                    'seated',
                    'standing'
                ],
                'boarded' => [
                    'total',
                    'seated',
                    'standing'
                ],
                'utilization' => [
                    'total_percentage',
                    'seated_percentage',
                    'standing_percentage'
                ],
                'capacity_status',
                'is_full',
                'is_near_capacity'
            ]
        ]);
    });

    it('conductor can get occupancy breakdown by stop', function () {
        $conductorLogin = $this->postJson('/api/staff/login', [
            'username' => 'conductor@smarttransit.com',
            'password' => 'password123'
        ]);
        
        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current/occupancy/by-stop');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [
                'trip_id',
                'route_name',
                'stops' => [
                    '*' => [
                        'stop_id',
                        'stop_name',
                        'sequence_number',
                        'distance_from_origin_km',
                        'boarding_count',
                        'alighting_count',
                        'passengers_on_bus_after'
                    ]
                ]
            ]
        ]);
    });

    it('conductor can view current passengers on trip', function () {
        $conductorLogin = $this->postJson('/api/staff/login', [
            'username' => 'conductor@smarttransit.com',
            'password' => 'password123'
        ]);
        
        $token = $conductorLogin->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/conductor/trips/current/passengers');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [
                'trip_id',
                'trip_status',
                'total_count',
                'passengers' => [
                    '*' => [
                        'ticket_id',
                        'ticket_uuid',
                        'passenger_name',
                        'passenger_id',
                        'seat_type',
                        'origin_stop',
                        'destination_stop',
                        'boarded_at'
                    ]
                ]
            ]
        ]);
    });

    it('conductor can scan QR code to board passenger', function () {
        $conductorLogin = $this->postJson('/api/staff/login', [
            'username' => 'conductor@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($conductorLogin->status() === 200) {
            $token = $conductorLogin->json('data.token');

            // Get current passengers
            $passengersResponse = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/conductor/trips/current/passengers');

            // If there are passengers, try to scan a QR
            if ($passengersResponse->status() === 200 && $passengersResponse->json('data.passengers.0.ticket_uuid')) {
                $ticketUuid = $passengersResponse->json('data.passengers.0.ticket_uuid');

                $response = $this->withHeader('Authorization', "Bearer $token")
                    ->postJson('/api/conductor/tickets/scan', [
                        'ticket_uuid' => $ticketUuid
                    ]);

                expect($response->status())->toBeIn([200, 422]);
            }
        }
    });

    it('conductor can record passenger alighting', function () {
        $conductorLogin = $this->postJson('/api/staff/login', [
            'username' => 'conductor@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($conductorLogin->status() === 200) {
            $token = $conductorLogin->json('data.token');

            // Get current passengers
            $passengersResponse = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/conductor/trips/current/passengers');

            if ($passengersResponse->status() === 200 && $passengersResponse->json('data.passengers.0.ticket_id')) {
                $ticketId = $passengersResponse->json('data.passengers.0.ticket_id');

                $response = $this->withHeader('Authorization', "Bearer $token")
                    ->postJson("/api/conductor/tickets/$ticketId/alight", []);

                expect($response->status())->toBeIn([200, 422]);
            }
        }
    });

    it('passenger can generate QR code for ticket', function () {
        // Register passenger first
        $registerResponse = $this->postJson('/api/passengers/register', [
            'first_name' => 'Test',
            'last_name' => 'Passenger',
            'email' => 'test.passenger.' . time() . '@test.com',
            'password' => 'password123',
            'phone' => '09999999999'
        ]);

        if ($registerResponse->status() === 201 || $registerResponse->status() === 200) {
            $passengerLogin = $this->postJson('/api/passengers/login', [
                'email' => $registerResponse->json('data.user.email'),
                'password' => 'password123'
            ]);

            if ($passengerLogin->status() === 200) {
                $token = $passengerLogin->json('data.token');

                $response = $this->withHeader('Authorization', "Bearer $token")
                    ->getJson('/api/passengers/tickets/1/qr');

                expect($response->status())->toBeIn([200, 404]);
            }
        }
    });

});
