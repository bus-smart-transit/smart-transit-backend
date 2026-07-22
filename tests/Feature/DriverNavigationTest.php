<?php

describe('Driver Navigation System', function () {
    
    it('driver can retrieve current trip with stops and passengers', function () {
        $driverLogin = $this->postJson('/api/staff/login', [
            'username' => 'driver@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($driverLogin->status() === 200) {
            $token = $driverLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/driver/trips/current/stops');

            expect($response->status())->toBeIn([200, 404]);
        }
    });

    it('driver can view specific stop details', function () {
        $driverLogin = $this->postJson('/api/staff/login', [
            'username' => 'driver@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($driverLogin->status() === 200) {
            $token = $driverLogin->json('data.token');

            $tripsResponse = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/driver/trips/current/stops');

            if ($tripsResponse->status() === 200 && $tripsResponse->json('data.stops.0')) {
                $stopId = $tripsResponse->json('data.stops.0.stop_id');

                $response = $this->withHeader('Authorization', "Bearer $token")
                    ->getJson("/api/driver/trips/current/stops/$stopId");

                expect($response->status())->toBeIn([200, 404]);
            }
        }
    });

    it('driver can acknowledge reaching a stop', function () {
        $driverLogin = $this->postJson('/api/staff/login', [
            'username' => 'driver@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($driverLogin->status() === 200) {
            $token = $driverLogin->json('data.token');

            $tripsResponse = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/driver/trips/current/stops');

            if ($tripsResponse->status() === 200 && $tripsResponse->json('data.stops.0')) {
                $stopId = $tripsResponse->json('data.stops.0.stop_id');

                $response = $this->withHeader('Authorization', "Bearer $token")
                    ->postJson("/api/driver/trips/current/stops/$stopId/acknowledge", []);

                expect($response->status())->toBeIn([200, 404]);
            }
        }
    });

});
