<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18: links each trip ("leg") to the shift block it belongs to.
 * Nullable — not every trip needs to belong to a shift block (existing
 * trips predating this feature, or any trip type this doesn't apply to).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('shift_block_id')->nullable()->after('conductor_id')
                ->constrained('shift_blocks', 'shift_block_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_block_id');
        });
    }
};
