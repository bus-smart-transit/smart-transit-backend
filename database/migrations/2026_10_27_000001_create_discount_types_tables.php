<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-27: onsite (cash) fare discounts. One list of discount types for the whole system (Student, Senior citizen,
 * Person with disability, 20% each: the Philippine rate under RA 11314, RA 9994 and RA 10754), and each Operator's
 * on/off choice per type. No row for an Operator means the type is on. Online fares use reward points instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('label', 64);
            $table->decimal('rate', 4, 3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('operator_discount_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_user_id')->constrained('company_users', 'company_user_id')->cascadeOnDelete();
            $table->foreignId('discount_type_id')->constrained('discount_types')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_user_id', 'discount_type_id']);
        });

        $now = now();
        DB::table('discount_types')->insert([
            ['key' => 'student', 'label' => 'Student', 'rate' => 0.200, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'senior', 'label' => 'Senior citizen', 'rate' => 0.200, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'pwd', 'label' => 'Person with disability', 'rate' => 0.200, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_discount_settings');
        Schema::dropIfExists('discount_types');
    }
};
