<?php

namespace Database\Seeders;

use App\Models\PassengerUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'passenger@smarttransit.com'],
            [
                'username' => 'passenger',
                'password' => 'password123',
                'role' => 'passenger',
            ]
        );

        PassengerUser::firstOrCreate(
            ['user_id' => $user->user_id],
            [
                'passenger_uuid' => (string) Str::uuid(),
                'name' => 'Default Passenger',
                'phone_num' => '09999999999',
                'address' => 'Davao City',
                'birth_date' => now(),
            ]
        );
    }
}
