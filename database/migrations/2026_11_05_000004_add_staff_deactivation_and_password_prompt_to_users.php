<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 46 (decided 2026-11-05). Additive, both columns nullable:
 * - users.deactivated_at: an Operator turned this Driver or Chauffeur off. Sign-in is refused, sessions are ended and
 *   the person is left out of assignment lists; their history stays. Null means active.
 * - users.password_prompted_at: when the crew member answered the one-time "change your password?" offer at their
 *   first sign-in (changed it or skipped it). Null means the offer is still due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('password_prompted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at', 'password_prompted_at']);
        });
    }
};
