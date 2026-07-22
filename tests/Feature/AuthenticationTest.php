<?php

use App\Models\User;

describe('Authentication & Authorization', function () {
    
    it('authenticates admin user and returns token', function () {
        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin@busmartransit.test',
            'password' => 'password123'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => ['token', 'user']
        ]);
        $response->assertJsonPath('status', 'success');
    });

    it('authenticates driver and returns token', function () {
        $response = $this->postJson('/api/staff/login', [
            'username' => 'driver@smarttransit.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => ['token']
        ]);
    });

    it('authenticates conductor and returns token', function () {
        $response = $this->postJson('/api/staff/login', [
            'username' => 'conductor@smarttransit.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    });

    it('rejects invalid credentials', function () {
        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin@busmartransit.test',
            'password' => 'wrongpassword'
        ]);

        $response->assertStatus(401);
    });

    it('enforces role-based access control on driver routes', function () {
        // Get conductor token
        $conductorLogin = $this->postJson('/api/staff/login', [
            'username' => 'conductor@smarttransit.com',
            'password' => 'password123'
        ]);
        
        $conductorToken = $conductorLogin->json('data.token');

        // Try to access driver-only route
        $response = $this->withHeader('Authorization', "Bearer $conductorToken")
            ->getJson('/api/driver/trips/current/stops');

        $response->assertStatus(403);
    });

    it('enforces role-based access control on conductor routes', function () {
        // Get driver token
        $driverLogin = $this->postJson('/api/staff/login', [
            'username' => 'driver@smarttransit.com',
            'password' => 'password123'
        ]);
        
        $driverToken = $driverLogin->json('data.token');

        // Try to access conductor-only route
        $response = $this->withHeader('Authorization', "Bearer $driverToken")
            ->getJson('/api/conductor/trips/current/occupancy');

        $response->assertStatus(403);
    });

});
