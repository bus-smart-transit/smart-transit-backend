<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * 2026-11-06: switch the "company admin" permission of an Operator on or off. A company admin manages the company's
 * buses, fares, staff, routes and stops; other Operators dispatch. Until the Admin portal (Batch 48), the platform
 * Admin runs this on the server.
 */
class SetCompanyAdmin extends Command
{
    protected $signature = 'staff:company-admin
        {email : the Operator\'s email}
        {--off : take the permission away}';

    protected $description = 'Make an Operator their company\'s admin (or take it away with --off)';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim((string) $this->argument('email'))))->first();
        $profile = $user?->companyProfile;
        if (! $user || $user->role !== 'operator' || ! $profile) {
            $this->error('No Operator account has that email.');

            return self::FAILURE;
        }
        if ($profile->company_id === null) {
            $this->error('This Operator is not linked to a company yet.');

            return self::FAILURE;
        }

        $on = ! $this->option('off');
        $profile->forceFill(['is_company_admin' => $on])->save();
        $this->info($on
            ? "{$user->email} is now a company admin of {$profile->company?->company_name}."
            : "{$user->email} is no longer a company admin.");

        return self::SUCCESS;
    }
}
