<?php

namespace App\Console\Commands;

use App\Services\AccountDeletionService;
use Illuminate\Console\Command;

/**
 * 2026-11-05: erases the personal data of passengers who asked to delete their account and did not sign in again
 * within the grace days (AccountDeletionService). Scheduled nightly in routes/console.php.
 */
class AnonymizeDeletedAccounts extends Command
{
    protected $signature = 'accounts:anonymize-deleted';

    protected $description = 'Erases the personal data of passenger accounts whose deletion grace period has ended.';

    public function handle(AccountDeletionService $deletions): int
    {
        $count = $deletions->anonymizeDue();
        $this->info("Erased {$count} account(s).");

        return self::SUCCESS;
    }
}
