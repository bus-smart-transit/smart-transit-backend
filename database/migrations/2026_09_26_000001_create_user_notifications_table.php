<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1 (Batch 17): persisted in-app notification history, one row per
 * FcmService::sendToUser() call (see FcmService — the same call site
 * writes both the push AND this row, so the bell icon and the push
 * notification the user got on their device are guaranteed to always
 * agree — there is no second, separate "notification system" to keep in
 * sync). Deliberately generic (any authenticated user, not role-specific)
 * so Driver, Conductor, Operator, and Passenger can all read their own
 * feed through the same table/repository — only the route prefix and
 * auth guard differ per role.
 *
 * Not RLS-protected: consistent with the existing `trip_notifications`
 * table (also a notification/audit-log-style table, scoped at the
 * application layer via user_id, not given Postgres RLS like the
 * financial/personal-data tables in 2026_09_08_* / 2026_09_08_130000_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id('notification_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
