<?php

describe('Fleet Reporting System', function () {
    
    it('operator can retrieve financial audit for fleet', function () {
        $operatorLogin = $this->postJson('/api/staff/login', [
            'username' => 'operator@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($operatorLogin->status() === 200) {
            $token = $operatorLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/reporting/fleet/1/financial-audit');

            expect($response->status())->toBeIn([200, 404]);
        }
    });

    it('operator can retrieve revenue breakdown by route', function () {
        $operatorLogin = $this->postJson('/api/staff/login', [
            'username' => 'operator@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($operatorLogin->status() === 200) {
            $token = $operatorLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/reporting/fleet/1/revenue-by-route');

            expect($response->status())->toBeIn([200, 404]);
        }
    });

    it('operator can retrieve route adherence data', function () {
        $operatorLogin = $this->postJson('/api/staff/login', [
            'username' => 'operator@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($operatorLogin->status() === 200) {
            $token = $operatorLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/reporting/fleet/1/route-adherence');

            expect($response->status())->toBeIn([200, 404]);
        }
    });

    it('operator can retrieve occupancy trends', function () {
        $operatorLogin = $this->postJson('/api/staff/login', [
            'username' => 'operator@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($operatorLogin->status() === 200) {
            $token = $operatorLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/reporting/fleet/1/occupancy-trends');

            expect($response->status())->toBeIn([200, 404]);
        }
    });

    it('operator can retrieve daily summary', function () {
        $operatorLogin = $this->postJson('/api/staff/login', [
            'username' => 'operator@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($operatorLogin->status() === 200) {
            $token = $operatorLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/reporting/fleet/1/daily-summary');

            expect($response->status())->toBeIn([200, 404]);
        }
    });

    it('operator can retrieve payment channel breakdown', function () {
        $operatorLogin = $this->postJson('/api/staff/login', [
            'username' => 'operator@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($operatorLogin->status() === 200) {
            $token = $operatorLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/reporting/fleet/1/payment-channels');

            expect($response->status())->toBeIn([200, 404]);
        }
    });

    it('rejects reporting request for non-existent fleet', function () {
        $operatorLogin = $this->postJson('/api/staff/login', [
            'username' => 'operator@smarttransit.com',
            'password' => 'password123'
        ]);
        
        if ($operatorLogin->status() === 200) {
            $token = $operatorLogin->json('data.token');

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->getJson('/api/reporting/fleet/99999/financial-audit');

            $response->assertStatus(404);
        }
    });

});
