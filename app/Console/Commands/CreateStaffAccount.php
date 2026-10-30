<?php

namespace App\Console\Commands;

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 2026-10-30: the first real account on a fresh server (usually the Operator; the Operator then adds drivers and
 * Chauffeurs on the Staffs page). The password is typed at a hidden prompt, never passed as an argument, so it is not
 * kept in the shell history or a file. Staff sign in with an emailed code, so the email must be one the person reads.
 */
class CreateStaffAccount extends Command
{
    protected $signature = 'staff:create
        {role : operator, admin, driver or conductor}
        {email : an address this person can read (the login code is emailed)}
        {name : full name}
        {phone : mobile number}
        {address : home or office address}';

    protected $description = 'Create one staff account; the password is asked for at a hidden prompt';

    private const ROLES = ['operator', 'admin', 'driver', 'conductor'];

    public function handle(): int
    {
        $data = [
            'role' => (string) $this->argument('role'),
            'email' => strtolower(trim((string) $this->argument('email'))),
            'name' => trim((string) $this->argument('name')),
            'phone' => trim((string) $this->argument('phone')),
            'address' => trim((string) $this->argument('address')),
        ];

        $check = Validator::make($data, [
            'role' => ['required', 'in:'.implode(',', self::ROLES)],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'regex:/^\+?[\d\s\-()]{7,15}$/'],
            'address' => ['required', 'string', 'max:255'],
        ]);
        if ($check->fails()) {
            foreach ($check->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 8 characters)');
        if (mb_strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Type the password again')) {
            $this->error('The two passwords do not match.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data, $password) {
            $user = User::create([
                'username' => $this->username($data['email']),
                'email' => $data['email'],
                'password' => $password,
                'role' => $data['role'],
            ]);
            StaffUser::create(['user_id' => $user->user_id, 'name' => $data['name'], 'phone_num' => $data['phone'], 'address' => $data['address']]);

            return $user;
        });

        $this->info("Created the {$data['role']} account {$user->email}. Sign in on the staff page; the login code is emailed there.");

        return self::SUCCESS;
    }

    /** The part of the email before "@", made unique ("ana", "ana2", ...). */
    private function username(string $email): string
    {
        $base = preg_replace('/[^a-z0-9._-]/', '', strstr($email, '@', true) ?: 'staff') ?: 'staff';
        $name = $base;
        for ($n = 2; User::where('username', $name)->exists(); $n++) {
            $name = $base.$n;
        }

        return $name;
    }
}
