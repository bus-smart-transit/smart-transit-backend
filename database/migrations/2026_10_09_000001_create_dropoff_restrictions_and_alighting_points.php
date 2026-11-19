<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Batch 28 Stage 3, item 10: designated alighting points and custom drop-off restriction areas, both entered as DATA.
 *
 *  routes.custom_dropoff_mode      off | designated_points | free_pin. Backfilled from the old routes.allow_custom_dropoff
 *                                  flag (true -> free_pin, false -> off) so no route changes behaviour. The flag stays
 *                                  and is kept in step with the mode by the Route model.
 *  custom_dropoff_restrictions     an area where custom drop-off is NOT available: a ROUTE SEGMENT between two route
 *                                  stops of one route (it covers both directions). from_distance_m / to_distance_m are the
 *                                  stops' stored distances when the row was saved: if a stop is later moved, reordered
 *                                  or deleted the row no longer matches them and is flagged needs_review (fails closed).
 *                                  area_type 'boundary' is reserved: no boundary geometry exists in the database yet.
 *  alighting_points                a named place (barangay | intersection | landmark) on a route where a passenger may
 *                                  get off outside the official stops. Distances along the route are computed by the
 *                                  server from the route line when the point is saved.
 *  tickets.alighting_point_id      which designated point a ticket was sold for (null for stops and free pins).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('custom_dropoff_mode', 20)->default('off')->after('allow_custom_dropoff');
        });
        DB::table('routes')->where('allow_custom_dropoff', true)->update(['custom_dropoff_mode' => 'free_pin']);

        Schema::create('custom_dropoff_restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('area_type', 20)->default('route_segment');
            $table->unsignedBigInteger('route_id');
            $table->unsignedBigInteger('from_route_stop_id')->nullable();
            $table->unsignedBigInteger('to_route_stop_id')->nullable();
            $table->integer('from_distance_m')->nullable();
            $table->integer('to_distance_m')->nullable();
            $table->string('applies_to', 30)->default('custom_dropoff');
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 255)->nullable();
            $table->string('source', 120)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('route_id')->references('route_id')->on('routes')->cascadeOnDelete();
            // A deleted end stop must not silently delete the restriction: the reference goes null and the row is flagged.
            $table->foreign('from_route_stop_id')->references('id')->on('route_stop')->nullOnDelete();
            $table->foreign('to_route_stop_id')->references('id')->on('route_stop')->nullOnDelete();
            $table->index(['route_id', 'is_active']);
        });

        Schema::create('alighting_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id');
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->string('municipality', 100)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->integer('distance_along_outbound_m')->nullable();
            $table->integer('distance_along_reverse_m')->nullable();
            $table->decimal('snap_distance_m', 8, 1)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source', 120)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('route_id')->references('route_id')->on('routes')->cascadeOnDelete();
            $table->index(['route_id', 'is_active']);
            $table->index('municipality');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('alighting_point_id')->nullable()->after('destination_label');
            $table->foreign('alighting_point_id')->references('id')->on('alighting_points')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['alighting_point_id']);
            $table->dropColumn('alighting_point_id');
        });
        Schema::dropIfExists('alighting_points');
        Schema::dropIfExists('custom_dropoff_restrictions');
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn('custom_dropoff_mode');
        });
    }
};
