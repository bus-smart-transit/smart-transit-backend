<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('step_up_tokens', function (Blueprint $table) {
            $table->id('token_id');
            $table->string('guard', 20)->default('sanctum'); // 'sanctum'
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->unique(); // SHA-256 of raw token
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'guard']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('step_up_tokens');
    }
};
