<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('idempotency_scope_hash', 64)->nullable();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->char('idempotency_request_hash', 64)->nullable();
            $table->string('idempotency_state', 16)->nullable();
            $table->json('idempotency_response')->nullable();
            $table->unique(['idempotency_scope_hash', 'idempotency_key_hash'], 'payments_idempotency_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_idempotency_scope_key_unique');
            $table->dropColumn([
                'idempotency_scope_hash',
                'idempotency_key_hash',
                'idempotency_request_hash',
                'idempotency_state',
                'idempotency_response',
            ]);
        });
    }
};