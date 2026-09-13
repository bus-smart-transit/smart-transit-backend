<?php

namespace App\Support\Database;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RlsContext
{
    private const KEY_ROLE = 'app.current_user_role';

    private const KEY_USER_ID = 'app.current_user_id';

    private const KEY_COMPANY_USER_ID = 'app.current_company_user_id';

    private const KEY_PASSENGER_ID = 'app.current_passenger_id';

    private const KEY_CURRENT_TRIP_ID = 'app.current_trip_id';

    private const KEY_GUEST_TRANSACTION_REFERENCE = 'app.guest_transaction_reference';

    private const KEY_GUEST_EMAIL = 'app.guest_email';

    private const KEY_GUEST_PAYMENT_ID = 'app.guest_payment_id';

    private const KEY_BYPASS = 'app.rls_bypass';

    public static function applyForRequest(?User $user): void
    {
        if (! self::usesPostgres()) {
            return;
        }

        $role = $user?->role ?: 'anonymous';

        self::set(self::KEY_ROLE, $role);
        self::set(self::KEY_USER_ID, $user?->user_id);
        self::set(self::KEY_COMPANY_USER_ID, $user?->companyProfile?->company_user_id);
        self::set(self::KEY_PASSENGER_ID, $user?->passengerProfile?->passenger_id);
        self::set(self::KEY_CURRENT_TRIP_ID, null);
        self::set(self::KEY_GUEST_TRANSACTION_REFERENCE, null);
        self::set(self::KEY_GUEST_EMAIL, null);
        self::set(self::KEY_GUEST_PAYMENT_ID, null);
        self::set(self::KEY_BYPASS, 'off');
    }

    public static function setCurrentTripId(?int $tripId): void
    {
        if (! self::usesPostgres()) {
            return;
        }

        self::set(self::KEY_CURRENT_TRIP_ID, $tripId);
    }

    public static function setGuestTicketLookup(?string $transactionReference, ?string $email, ?int $paymentId): void
    {
        if (! self::usesPostgres()) {
            return;
        }

        self::set(self::KEY_GUEST_TRANSACTION_REFERENCE, $transactionReference !== null ? trim($transactionReference) : null);
        self::set(self::KEY_GUEST_EMAIL, $email !== null ? trim($email) : null);
        self::set(self::KEY_GUEST_PAYMENT_ID, $paymentId);
    }

    public static function enableBypass(): void
    {
        if (! self::usesPostgres()) {
            return;
        }

        self::set(self::KEY_ROLE, 'system');
        self::set(self::KEY_USER_ID, null);
        self::set(self::KEY_COMPANY_USER_ID, null);
        self::set(self::KEY_PASSENGER_ID, null);
        self::set(self::KEY_CURRENT_TRIP_ID, null);
        self::set(self::KEY_GUEST_TRANSACTION_REFERENCE, null);
        self::set(self::KEY_GUEST_EMAIL, null);
        self::set(self::KEY_GUEST_PAYMENT_ID, null);
        self::set(self::KEY_BYPASS, 'on');
    }

    public static function withBypass(callable $callback): mixed
    {
        if (! self::usesPostgres()) {
            return $callback();
        }

        $snapshot = self::snapshot();
        self::enableBypass();

        try {
            return $callback();
        } finally {
            self::restore($snapshot);
        }
    }

    public static function reset(): void
    {
        if (! self::usesPostgres()) {
            return;
        }

        self::set(self::KEY_ROLE, 'anonymous');
        self::set(self::KEY_USER_ID, null);
        self::set(self::KEY_COMPANY_USER_ID, null);
        self::set(self::KEY_PASSENGER_ID, null);
        self::set(self::KEY_CURRENT_TRIP_ID, null);
        self::set(self::KEY_GUEST_TRANSACTION_REFERENCE, null);
        self::set(self::KEY_GUEST_EMAIL, null);
        self::set(self::KEY_GUEST_PAYMENT_ID, null);
        self::set(self::KEY_BYPASS, 'off');
    }

    public static function usesPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private static function snapshot(): array
    {
        $row = DB::selectOne(
            "SELECT
                current_setting('app.current_user_role', true) AS role,
                current_setting('app.current_user_id', true) AS user_id,
                current_setting('app.current_company_user_id', true) AS company_user_id,
                current_setting('app.current_passenger_id', true) AS passenger_id,
                current_setting('app.current_trip_id', true) AS trip_id,
                current_setting('app.guest_transaction_reference', true) AS guest_transaction_reference,
                current_setting('app.guest_email', true) AS guest_email,
                current_setting('app.guest_payment_id', true) AS guest_payment_id,
                current_setting('app.rls_bypass', true) AS bypass"
        );

        return [
            self::KEY_ROLE => $row->role ?? null,
            self::KEY_USER_ID => $row->user_id ?? null,
            self::KEY_COMPANY_USER_ID => $row->company_user_id ?? null,
            self::KEY_PASSENGER_ID => $row->passenger_id ?? null,
            self::KEY_CURRENT_TRIP_ID => $row->trip_id ?? null,
            self::KEY_GUEST_TRANSACTION_REFERENCE => $row->guest_transaction_reference ?? null,
            self::KEY_GUEST_EMAIL => $row->guest_email ?? null,
            self::KEY_GUEST_PAYMENT_ID => $row->guest_payment_id ?? null,
            self::KEY_BYPASS => $row->bypass ?? null,
        ];
    }

    private static function restore(array $snapshot): void
    {
        self::set(self::KEY_ROLE, $snapshot[self::KEY_ROLE] ?? null);
        self::set(self::KEY_USER_ID, $snapshot[self::KEY_USER_ID] ?? null);
        self::set(self::KEY_COMPANY_USER_ID, $snapshot[self::KEY_COMPANY_USER_ID] ?? null);
        self::set(self::KEY_PASSENGER_ID, $snapshot[self::KEY_PASSENGER_ID] ?? null);
        self::set(self::KEY_CURRENT_TRIP_ID, $snapshot[self::KEY_CURRENT_TRIP_ID] ?? null);
        self::set(self::KEY_GUEST_TRANSACTION_REFERENCE, $snapshot[self::KEY_GUEST_TRANSACTION_REFERENCE] ?? null);
        self::set(self::KEY_GUEST_EMAIL, $snapshot[self::KEY_GUEST_EMAIL] ?? null);
        self::set(self::KEY_GUEST_PAYMENT_ID, $snapshot[self::KEY_GUEST_PAYMENT_ID] ?? null);
        self::set(self::KEY_BYPASS, $snapshot[self::KEY_BYPASS] ?? null);
    }

    private static function set(string $key, string|int|null $value): void
    {
        DB::select('SELECT set_config(?, ?, false)', [
            $key,
            $value === null ? '' : (string) $value,
        ]);
    }
}
