<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passenger account deletion (2026-11-05, App\Services\AccountDeletionService)
    |--------------------------------------------------------------------------
    |
    | A passenger deletes only their own account. It is locked at once (every
    | session ends); signing in again within `account_deletion_grace_days`
    | cancels it. After that the nightly `accounts:anonymize-deleted` job
    | erases the personal data for good; tickets, payments and refunds stay,
    | without a name, for the financial records.
    |
    */

    'account_deletion_grace_days' => (int) env('ACCOUNT_DELETION_GRACE_DAYS', 7),
];
