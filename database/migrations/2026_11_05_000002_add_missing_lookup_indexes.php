<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-05 (Batch 45 Todo 3, index question): lookups the app makes on every request that had no index. At demo size
 * PostgreSQL scans these small tables anyway; the indexes matter as the data grows (measured: a payment found by its
 * reference among 300,000 rows took 25.8 ms without an index and 0.1 ms with one). Plain indexes only (no unique
 * constraint), so no existing row can make the migration fail. Additive: no column or data changes.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => [index name => columns] */
    private array $indexes = [
        'payments' => [
            'payments_transaction_reference_idx' => ['transaction_reference'], // status checks, guest lookup, webhooks
            'payments_payment_intent_id_idx' => ['payment_intent_id'],         // webhook matching
        ],
        'trips' => [
            'trips_trip_date_status_idx' => ['trip_date', 'status'],            // booking lists and schedules
            'trips_company_user_date_idx' => ['company_user_id', 'trip_date'],  // an Operator's own trips
        ],
        'tickets' => [
            'tickets_payment_id_idx' => ['payment_id'],                        // refunds, confirmation, Finance
        ],
        'route_stop' => [
            'route_stop_route_order_idx' => ['route_id', 'stop_order'],        // a route's stops in order
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }
};
