<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Batch 24: the municipality/city a stop is in, chosen from the list in
        // config/region.php. Corridor names and destination grouping derive
        // from this data; no place name is hardcoded anywhere.
        Schema::table('stops', function (Blueprint $table) {
            $table->string('municipality', 100)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->dropColumn('municipality');
        });
    }
};
