<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\Fleet;
use App\Models\Route;
use App\Models\Stop;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batch 48 Phase 2: the counts on the Super Admin Dashboard, across every company. Counts only; no personal data.
 */
class AdminOverviewRepository
{
    public function companies(): int
    {
        return Company::count();
    }

    public function activeOperators(): int
    {
        return User::where('role', 'operator')->whereNull('deactivated_at')->count();
    }

    public function buses(): int
    {
        return Fleet::count();
    }

    public function routes(): int
    {
        return Route::count();
    }

    public function tripsOn(string $date): int
    {
        return Trip::whereDate('trip_date', $date)->count();
    }

    /** Tickets made on $date (Manila wall clock, as stored) whose payment is paid, online or cash. */
    public function ticketsPaidOn(string $date): int
    {
        return DB::table('tickets')
            ->join('payments', 'payments.payment_id', '=', 'tickets.payment_id')
            ->where('payments.status', 'paid')
            ->whereDate('tickets.created_at', $date)
            ->count();
    }

    /** Payments the system took but could not turn into tickets: a person must refund them. */
    public function paymentsNeedingRefund(): int
    {
        return DB::table('payments')->where('status', 'needs_refund')->count();
    }

    public function failedJobs(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /** Batch 48 Phase 6: jobs waiting for the worker. */
    public function pendingJobs(): int
    {
        return DB::table('jobs')->count();
    }

    /** When the oldest waiting job was queued (unix seconds), or null when none waits. */
    public function oldestPendingJobAt(): ?int
    {
        $oldest = DB::table('jobs')->min('created_at');

        return $oldest !== null ? (int) $oldest : null;
    }

    /** True when the database answers. */
    public function databaseAnswers(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Stops placed from their name before pins (Batch 47 part A): their company admin should re-pin them. */
    public function stopsNotPinned(): int
    {
        return Stop::where('location_source', 'name')->count();
    }
}
