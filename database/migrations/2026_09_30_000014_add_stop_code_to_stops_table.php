<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Batch 25, Issue 3: stable, human-readable stop codes for shareable booking
 * links (?from=<code>&to=<code>) so no internal numeric id appears in a URL.
 * Backfilled from the stop name with a collision-safe suffix; new stops get a
 * code from the model; an admin can edit it (links keep working while it stays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->string('stop_code', 64)->nullable()->after('stop_name');
        });

        $taken = [];
        foreach (DB::table('stops')->orderBy('stop_id')->get(['stop_id', 'stop_name']) as $stop) {
            $base = Str::limit(Str::slug((string) $stop->stop_name) ?: 'stop', 56, '');
            $code = $base;
            for ($i = 2; isset($taken[$code]); $i++) {
                $code = $base.'-'.$i;
            }
            $taken[$code] = true;
            DB::table('stops')->where('stop_id', $stop->stop_id)->update(['stop_code' => $code]);
        }

        Schema::table('stops', function (Blueprint $table) {
            $table->unique('stop_code');
        });
    }

    public function down(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->dropUnique(['stop_code']);
            $table->dropColumn('stop_code');
        });
    }
};
