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
CREATE OR REPLACE FUNCTION app_rls_role() RETURNS text
LANGUAGE sql STABLE
AS $$
    SELECT COALESCE(NULLIF(current_setting('app.current_user_role', true), ''), 'anonymous')
$$;

CREATE OR REPLACE FUNCTION app_rls_user_id() RETURNS bigint
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.current_user_id', true), '')::bigint
$$;

CREATE OR REPLACE FUNCTION app_rls_company_user_id() RETURNS bigint
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.current_company_user_id', true), '')::bigint
$$;

CREATE OR REPLACE FUNCTION app_rls_passenger_id() RETURNS bigint
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.current_passenger_id', true), '')::bigint
$$;

CREATE OR REPLACE FUNCTION app_rls_bypass() RETURNS boolean
LANGUAGE sql STABLE
AS $$
    SELECT COALESCE(NULLIF(current_setting('app.rls_bypass', true), ''), 'off') = 'on'
$$;

ALTER TABLE trips ENABLE ROW LEVEL SECURITY;
ALTER TABLE trips FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS trips_select ON trips;
CREATE POLICY trips_select ON trips
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() IN ('admin', 'anonymous', 'passenger')
        OR (app_rls_role() = 'operator' AND company_user_id = app_rls_company_user_id())
        OR (app_rls_role() = 'driver' AND driver_id = app_rls_company_user_id())
        OR (app_rls_role() = 'conductor' AND conductor_id = app_rls_company_user_id())
    );
DROP POLICY IF EXISTS trips_insert ON trips;
CREATE POLICY trips_insert ON trips
    FOR INSERT
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (app_rls_role() = 'operator' AND company_user_id = app_rls_company_user_id())
    );
DROP POLICY IF EXISTS trips_update ON trips;
CREATE POLICY trips_update ON trips
    FOR UPDATE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (app_rls_role() = 'operator' AND company_user_id = app_rls_company_user_id())
        OR (app_rls_role() = 'driver' AND driver_id = app_rls_company_user_id())
    )
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (app_rls_role() = 'operator' AND company_user_id = app_rls_company_user_id())
        OR (app_rls_role() = 'driver' AND driver_id = app_rls_company_user_id())
    );
DROP POLICY IF EXISTS trips_delete ON trips;
CREATE POLICY trips_delete ON trips
    FOR DELETE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (app_rls_role() = 'operator' AND company_user_id = app_rls_company_user_id())
    );

ALTER TABLE fare_rules ENABLE ROW LEVEL SECURITY;
ALTER TABLE fare_rules FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS fare_rules_select ON fare_rules;
CREATE POLICY fare_rules_select ON fare_rules
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() IN ('admin', 'anonymous', 'passenger', 'driver', 'conductor')
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = fare_rules.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
    );
DROP POLICY IF EXISTS fare_rules_insert ON fare_rules;
CREATE POLICY fare_rules_insert ON fare_rules
    FOR INSERT
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = fare_rules.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
    );
DROP POLICY IF EXISTS fare_rules_update ON fare_rules;
CREATE POLICY fare_rules_update ON fare_rules
    FOR UPDATE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = fare_rules.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
    )
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = fare_rules.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
    );
DROP POLICY IF EXISTS fare_rules_delete ON fare_rules;
CREATE POLICY fare_rules_delete ON fare_rules
    FOR DELETE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = fare_rules.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
    );

ALTER TABLE fleet_locations ENABLE ROW LEVEL SECURITY;
ALTER TABLE fleet_locations FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS fleet_locations_select ON fleet_locations;
CREATE POLICY fleet_locations_select ON fleet_locations
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() IN ('admin', 'anonymous', 'passenger')
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = fleet_locations.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
        OR (
            app_rls_role() = 'driver'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_locations.trip_id
                  AND trips.driver_id = app_rls_company_user_id()
            )
        )
        OR (
            app_rls_role() = 'conductor'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_locations.trip_id
                  AND trips.conductor_id = app_rls_company_user_id()
            )
        )
    );
DROP POLICY IF EXISTS fleet_locations_insert ON fleet_locations;
CREATE POLICY fleet_locations_insert ON fleet_locations
    FOR INSERT
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'driver'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_locations.trip_id
                  AND trips.driver_id = app_rls_company_user_id()
            )
        )
    );
DROP POLICY IF EXISTS fleet_locations_update ON fleet_locations;
CREATE POLICY fleet_locations_update ON fleet_locations
    FOR UPDATE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'driver'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_locations.trip_id
                  AND trips.driver_id = app_rls_company_user_id()
            )
        )
    )
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'driver'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_locations.trip_id
                  AND trips.driver_id = app_rls_company_user_id()
            )
        )
    );

ALTER TABLE fleet_location_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE fleet_location_history FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS fleet_location_history_select ON fleet_location_history;
CREATE POLICY fleet_location_history_select ON fleet_location_history
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'operator'
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = fleet_location_history.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
        OR (
            app_rls_role() = 'driver'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_location_history.trip_id
                  AND trips.driver_id = app_rls_company_user_id()
            )
        )
        OR (
            app_rls_role() = 'conductor'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_location_history.trip_id
                  AND trips.conductor_id = app_rls_company_user_id()
            )
        )
    );
DROP POLICY IF EXISTS fleet_location_history_insert ON fleet_location_history;
CREATE POLICY fleet_location_history_insert ON fleet_location_history
    FOR INSERT
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'driver'
            AND EXISTS (
                SELECT 1 FROM trips
                WHERE trips.trip_id = fleet_location_history.trip_id
                  AND trips.driver_id = app_rls_company_user_id()
            )
        )
    );

ALTER TABLE scheduled_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE scheduled_reports FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS scheduled_reports_select ON scheduled_reports;
CREATE POLICY scheduled_reports_select ON scheduled_reports
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR (
            app_rls_role() = 'operator'
            AND fleet_id IS NOT NULL
            AND EXISTS (
                SELECT 1 FROM fleets
                WHERE fleets.fleet_id = scheduled_reports.fleet_id
                  AND fleets.company_user_id = app_rls_company_user_id()
            )
        )
    );
DROP POLICY IF EXISTS scheduled_reports_insert ON scheduled_reports;
CREATE POLICY scheduled_reports_insert ON scheduled_reports
    FOR INSERT
    WITH CHECK (app_rls_bypass());
DROP POLICY IF EXISTS scheduled_reports_update ON scheduled_reports;
CREATE POLICY scheduled_reports_update ON scheduled_reports
    FOR UPDATE
    USING (app_rls_bypass())
    WITH CHECK (app_rls_bypass());
DROP POLICY IF EXISTS scheduled_reports_delete ON scheduled_reports;
CREATE POLICY scheduled_reports_delete ON scheduled_reports
    FOR DELETE
    USING (app_rls_bypass());

ALTER TABLE step_up_tokens ENABLE ROW LEVEL SECURITY;
ALTER TABLE step_up_tokens FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS step_up_tokens_select ON step_up_tokens;
CREATE POLICY step_up_tokens_select ON step_up_tokens
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR user_id = app_rls_user_id()
    );
DROP POLICY IF EXISTS step_up_tokens_insert ON step_up_tokens;
CREATE POLICY step_up_tokens_insert ON step_up_tokens
    FOR INSERT
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR user_id = app_rls_user_id()
    );
DROP POLICY IF EXISTS step_up_tokens_update ON step_up_tokens;
CREATE POLICY step_up_tokens_update ON step_up_tokens
    FOR UPDATE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR user_id = app_rls_user_id()
    )
    WITH CHECK (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR user_id = app_rls_user_id()
    );
DROP POLICY IF EXISTS step_up_tokens_delete ON step_up_tokens;
CREATE POLICY step_up_tokens_delete ON step_up_tokens
    FOR DELETE
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
        OR user_id = app_rls_user_id()
    );

ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS audit_logs_select ON audit_logs;
CREATE POLICY audit_logs_select ON audit_logs
    FOR SELECT
    USING (
        app_rls_bypass()
        OR app_rls_role() = 'admin'
    );
DROP POLICY IF EXISTS audit_logs_insert ON audit_logs;
CREATE POLICY audit_logs_insert ON audit_logs
    FOR INSERT
    WITH CHECK (true);
DROP POLICY IF EXISTS audit_logs_update ON audit_logs;
CREATE POLICY audit_logs_update ON audit_logs
    FOR UPDATE
    USING (app_rls_bypass())
    WITH CHECK (app_rls_bypass());
DROP POLICY IF EXISTS audit_logs_delete ON audit_logs;
CREATE POLICY audit_logs_delete ON audit_logs
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
DROP POLICY IF EXISTS audit_logs_delete ON audit_logs;
DROP POLICY IF EXISTS audit_logs_update ON audit_logs;
DROP POLICY IF EXISTS audit_logs_insert ON audit_logs;
DROP POLICY IF EXISTS audit_logs_select ON audit_logs;
ALTER TABLE audit_logs DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS step_up_tokens_delete ON step_up_tokens;
DROP POLICY IF EXISTS step_up_tokens_update ON step_up_tokens;
DROP POLICY IF EXISTS step_up_tokens_insert ON step_up_tokens;
DROP POLICY IF EXISTS step_up_tokens_select ON step_up_tokens;
ALTER TABLE step_up_tokens DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS scheduled_reports_delete ON scheduled_reports;
DROP POLICY IF EXISTS scheduled_reports_update ON scheduled_reports;
DROP POLICY IF EXISTS scheduled_reports_insert ON scheduled_reports;
DROP POLICY IF EXISTS scheduled_reports_select ON scheduled_reports;
ALTER TABLE scheduled_reports DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS fleet_location_history_insert ON fleet_location_history;
DROP POLICY IF EXISTS fleet_location_history_select ON fleet_location_history;
ALTER TABLE fleet_location_history DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS fleet_locations_update ON fleet_locations;
DROP POLICY IF EXISTS fleet_locations_insert ON fleet_locations;
DROP POLICY IF EXISTS fleet_locations_select ON fleet_locations;
ALTER TABLE fleet_locations DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS fare_rules_delete ON fare_rules;
DROP POLICY IF EXISTS fare_rules_update ON fare_rules;
DROP POLICY IF EXISTS fare_rules_insert ON fare_rules;
DROP POLICY IF EXISTS fare_rules_select ON fare_rules;
ALTER TABLE fare_rules DISABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS trips_delete ON trips;
DROP POLICY IF EXISTS trips_update ON trips;
DROP POLICY IF EXISTS trips_insert ON trips;
DROP POLICY IF EXISTS trips_select ON trips;
ALTER TABLE trips DISABLE ROW LEVEL SECURITY;

DROP FUNCTION IF EXISTS app_rls_bypass();
DROP FUNCTION IF EXISTS app_rls_passenger_id();
DROP FUNCTION IF EXISTS app_rls_company_user_id();
DROP FUNCTION IF EXISTS app_rls_user_id();
DROP FUNCTION IF EXISTS app_rls_role();
SQL);
    }
};
