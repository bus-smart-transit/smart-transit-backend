<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 28, Stage 2, item 5: fleet class and the fare key.
     *
     * - fleets.vehicle_class: nullable until the operator sets it; the allowed values are config('fares.vehicle_classes').
     * - fare_rules: vehicle_class (nullable), service_type (regular|express, existing rows become regular),
     *   route_id (nullable), rounding_mode (nearest|ceiling, existing rows keep nearest = today's behaviour) and a
     *   free note (used to label sample data). fleet_id becomes nullable: a rule WITH a fleet is an override for
     *   that fleet; a rule without one is a general rule found by (route, class, service type, seat type).
     *   Existing rows keep their fleet_id, so every current fare is unchanged.
     * - tickets.rounding_mode: the rounding the ticket's fare was computed with (snapshot; old tickets stay null).
     * - tickets.journey_leg: outbound | return for the two tickets of a roundtrip booking (null otherwise).
     */
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->string('vehicle_class', 32)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Only the NOT NULL goes: the column's type must stay as it is because row-level-security policies depend on it.
            DB::statement('ALTER TABLE fare_rules ALTER COLUMN fleet_id DROP NOT NULL');
        } else {
            Schema::table('fare_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('fleet_id')->nullable()->change();
            });
        }

        Schema::table('fare_rules', function (Blueprint $table) {
            $table->string('vehicle_class', 32)->nullable();
            $table->string('service_type', 16)->default('regular');
            $table->unsignedBigInteger('route_id')->nullable();
            $table->string('rounding_mode', 16)->default('nearest');
            $table->string('note', 255)->nullable();
            $table->index(['vehicle_class', 'service_type', 'seat_type'], 'fare_rules_general_key_idx');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('fare_rules', function (Blueprint $table) {
                $table->foreign('route_id')->references('route_id')->on('routes')->nullOnDelete();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            // A general rule (no fleet) is fare data every other role can already read. The one change to the
            // existing select policy: an operator may read those rows too (it still sees only its own fleets'
            // overrides). Insert, update and delete policies are untouched: only an admin writes a general rule.
            DB::statement('DROP POLICY IF EXISTS fare_rules_select ON fare_rules');
            DB::statement("CREATE POLICY fare_rules_select ON fare_rules FOR SELECT USING (
                app_rls_bypass()
                OR fleet_id IS NULL
                OR app_rls_role() = ANY (ARRAY['admin','anonymous','passenger','driver','conductor'])
                OR (app_rls_role() = 'operator' AND EXISTS (SELECT 1 FROM fleets WHERE fleets.fleet_id = fare_rules.fleet_id AND fleets.company_user_id = app_rls_company_user_id()))
            )");
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('rounding_mode', 16)->nullable();
            $table->string('journey_leg', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS fare_rules_select ON fare_rules');
            DB::statement("CREATE POLICY fare_rules_select ON fare_rules FOR SELECT USING (
                app_rls_bypass()
                OR app_rls_role() = ANY (ARRAY['admin','anonymous','passenger','driver','conductor'])
                OR (app_rls_role() = 'operator' AND EXISTS (SELECT 1 FROM fleets WHERE fleets.fleet_id = fare_rules.fleet_id AND fleets.company_user_id = app_rls_company_user_id()))
            )");
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['rounding_mode', 'journey_leg']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('fare_rules', function (Blueprint $table) {
                $table->dropForeign(['route_id']);
            });
        }

        Schema::table('fare_rules', function (Blueprint $table) {
            $table->dropIndex('fare_rules_general_key_idx');
            $table->dropColumn(['vehicle_class', 'service_type', 'route_id', 'rounding_mode', 'note']);
        });

        Schema::table('fleets', function (Blueprint $table) {
            $table->dropColumn('vehicle_class');
        });
    }
};
