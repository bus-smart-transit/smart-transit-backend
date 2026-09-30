<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // D5: a custom drop-off is a requested stop, so the ticket keeps its label
        // (the snapped point is already stored in destination_lat/lng).
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('destination_label', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('destination_label');
        });
    }
};