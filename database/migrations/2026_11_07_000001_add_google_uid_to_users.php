<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-07 (user approved): passengers may sign in with Google (Firebase Authentication). users.google_uid is the
 * Firebase user id of the Google account linked to this SmartTransit account; null = not linked. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_uid', 128)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_uid']);
            $table->dropColumn('google_uid');
        });
    }
};
