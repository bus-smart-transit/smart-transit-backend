<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency marker for the "trip auto-delayed" passenger notification.
     *
     * TripRepository::markOverdueScheduledAsDelayed() flips a trip's status
     * from 'scheduled' to 'delayed' as a side effect of 10+ different
     * read-query call sites (operator/driver/conductor/passenger trip
     * listings) — there is no single authoritative place where that
     * transition happens, so sending a notification directly from inside
     * that method would risk double sends (or races between call sites).
     *
     * Instead, a dedicated scheduled command (trips:notify-delayed) polls
     * for trips already marked 'delayed' with a null delay_notified_at,
     * sends the notification, and atomically claims it by setting this
     * column — regardless of which of the many call sites performed the
     * actual status flip.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('delay_notified_at')->nullable()->after('conductor_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('delay_notified_at');
        });
    }
};
