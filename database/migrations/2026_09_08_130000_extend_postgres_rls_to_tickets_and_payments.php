<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_rls_current_trip_id() RETURNS bigint
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.current_trip_id', true), '')::bigint
$$;

CREATE OR REPLACE FUNCTION app_rls_guest_transaction_reference() RETURNS text
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.guest_transaction_reference', true), '')
$$;

CREATE OR REPLACE FUNCTION app_rls_guest_email() RETURNS text
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.guest_email', true), '')
$$;

CREATE OR REPLACE FUNCTION app_rls_guest_payment_id() RETURNS bigint
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.guest_payment_id', true), '')::bigint
$$;

ALTER TABLE payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE payments FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS payments_select ON payments;
CREATE POLICY payments_select ON payments
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'passenger'
            AND EXISTS (
                SELECT 1 FROM tickets
                WHERE tickets.payment_id = payments.payment_id
                  AND tickets.passenger_id = app_rls_passenger_id()
            )
        )
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM tickets
                JOIN trips ON trips.trip_id = tickets.trip_id
                WHERE tickets.payment_id = payments.payment_id
                  AND trips.company_user_id = app_rls_company_user_id()
            )
        )
        OR (
            app_rls_role() IN ('driver', 'conductor')
            AND app_rls_current_trip_id() IS NOT NULL
            AND EXISTS (
                SELECT 1 FROM tickets
                WHERE tickets.payment_id = payments.payment_id
                  AND tickets.trip_id = app_rls_current_trip_id()
            )
        )
        OR (
            app_rls_role() = 'anonymous'
            AND payments.transaction_reference = app_rls_guest_transaction_reference()
            AND (
                app_rls_guest_email() IS NULL
                OR payments.guest_email = app_rls_guest_email()
            )
            AND (
                app_rls_guest_payment_id() IS NULL
                OR payments.payment_id = app_rls_guest_payment_id()
            )
        )
    );
DROP POLICY IF EXISTS payments_insert ON payments;
CREATE POLICY payments_insert ON payments
    FOR INSERT
    WITH CHECK (app_rls_bypass());
DROP POLICY IF EXISTS payments_update ON payments;
CREATE POLICY payments_update ON payments
    FOR UPDATE
    USING (app_rls_bypass())
    WITH CHECK (app_rls_bypass());
DROP POLICY IF EXISTS payments_delete ON payments;
CREATE POLICY payments_delete ON payments
    FOR DELETE
    USING (app_rls_bypass());

ALTER TABLE tickets ENABLE ROW LEVEL SECURITY;
ALTER TABLE tickets FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tickets_select ON tickets;
CREATE POLICY tickets_select ON tickets
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (app_rls_role() = 'passenger' AND passenger_id = app_rls_passenger_id())
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = tickets.trip_id
                  AND trips.company_user_id = app_rls_company_user_id()
            )
        )
        OR (
            app_rls_role() IN ('driver', 'conductor')
            AND app_rls_current_trip_id() IS NOT NULL
            AND tickets.trip_id = app_rls_current_trip_id()
        )
        OR (
            app_rls_role() = 'anonymous'
            AND EXISTS (
                SELECT 1 FROM payments
                WHERE payments.payment_id = tickets.payment_id
                  AND payments.transaction_reference = app_rls_guest_transaction_reference()
                  AND (
                      app_rls_guest_email() IS NULL
                      OR payments.guest_email = app_rls_guest_email()
                  )
                  AND (
                      app_rls_guest_payment_id() IS NULL
                      OR payments.payment_id = app_rls_guest_payment_id()
                  )
            )
        )
    );
DROP POLICY IF EXISTS tickets_insert ON tickets;
CREATE POLICY tickets_insert ON tickets
    FOR INSERT
    WITH CHECK (app_rls_bypass());
DROP POLICY IF EXISTS tickets_update ON tickets;
CREATE POLICY tickets_update ON tickets
    FOR UPDATE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'conductor'
            AND app_rls_current_trip_id() IS NOT NULL
            AND tickets.trip_id = app_rls_current_trip_id()
        )
    )
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'conductor'
            AND app_rls_current_trip_id() IS NOT NULL
            AND tickets.trip_id = app_rls_current_trip_id()
        )
    );
DROP POLICY IF EXISTS tickets_delete ON tickets;
CREATE POLICY tickets_delete ON tickets
    FOR DELETE
    USING (app_rls_bypass());
SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP POLICY IF EXISTS tickets_delete ON tickets;
DROP POLICY IF EXISTS tickets_update ON tickets;
DROP POLICY IF EXISTS tickets_insert ON tickets;
DROP POLICY IF EXISTS tickets_select ON tickets;
ALTER TABLE tickets DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS payments_delete ON payments;
DROP POLICY IF EXISTS payments_update ON payments;
DROP POLICY IF EXISTS payments_insert ON payments;
DROP POLICY IF EXISTS payments_select ON payments;
ALTER TABLE payments DISABLE ROW LEVEL SECURITY;

DROP FUNCTION IF EXISTS app_rls_guest_payment_id();
DROP FUNCTION IF EXISTS app_rls_guest_email();
DROP FUNCTION IF EXISTS app_rls_guest_transaction_reference();
DROP FUNCTION IF EXISTS app_rls_current_trip_id();
SQL);
    }
};
