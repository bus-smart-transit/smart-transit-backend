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

        // 2026-10-28: the demo passenger counts as email-verified.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

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
