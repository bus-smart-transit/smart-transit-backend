<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename all existing 'issued' ticket statuses to 'valid'.
     * The user renamed the status in TicketService; this aligns the DB records.
     */
    public function up(): void
    {
        DB::table('tickets')->where('status', 'issued')->update(['status' => 'valid']);
    }

    public function down(): void
    {
        DB::table('tickets')->where('status', 'valid')->update(['status' => 'issued']);
    }
};
