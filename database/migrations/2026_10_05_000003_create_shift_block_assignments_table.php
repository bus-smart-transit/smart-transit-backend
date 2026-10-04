<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 28 (item 1): one assignment record per shift block and role.
     *
     * Until now "accepted" lived only on each leg (trips.driver_accepted_at / conductor_accepted_at) and a
     * decline was one trip_assignment_requests row per leg, so a four-leg block needed four accepts. This
     * table is the block-level decision. Accepting or declining writes it AND the legs in one transaction;
     * the leg columns stay (other code reads them). A standalone one-way / ad hoc trip is a block of one leg
     * and is keyed by trip_id instead of shift_block_id.
     *
     * shift_blocks.driver_id / conductor_id become nullable: when the Operator approves a decline the
     * released person is removed from the whole block and the block is left needing a replacement.
     *
     * The backfill is derived from the existing leg data only (never invented): a block is "accepted" when every
     * live leg is accepted, "for_approval" / "rejected" when the latest leg request from the holder says so.
     */
    public function up(): void
    {
        Schema::table('shift_blocks', function (Blueprint $table) {
            $table->unsignedBigInteger('driver_id')->nullable()->change();
            $table->unsignedBigInteger('conductor_id')->nullable()->change();
        });

        Schema::create('shift_block_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_block_id')->nullable();
            $table->unsignedBigInteger('trip_id')->nullable();
            $table->string('role', 16); // driver | conductor
            $table->unsignedBigInteger('staff_company_user_id');
            $table->string('status', 16)->default('pending'); // pending | accepted | for_approval | rejected | released
            $table->timestamp('accepted_at')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->string('reason_text', 300)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('decided_by_company_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('shift_block_id')->references('shift_block_id')->on('shift_blocks')->cascadeOnDelete();
            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
            $table->unique(['shift_block_id', 'role']);
            $table->unique(['trip_id', 'role']);
            $table->index(['staff_company_user_id', 'status']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_block_assignments');
        // shift_blocks.driver_id / conductor_id are left nullable: restoring NOT NULL would fail
        // on any block released since, and a nullable column is harmless to older code.
    }

    private function backfill(): void
    {
        foreach (DB::table('shift_blocks')->get() as $block) {
            $legs = DB::table('trips')->where('shift_block_id', $block->shift_block_id)->get();
            foreach (['driver', 'conductor'] as $role) {
                $staffId = $block->{$role.'_id'};
                if ($staffId) {
                    $this->insertRecord(['shift_block_id' => $block->shift_block_id, 'trip_id' => null], $role, (int) $staffId, $legs);
                }
            }
        }

        foreach (DB::table('trips')->whereNull('shift_block_id')->get() as $trip) {
            foreach (['driver', 'conductor'] as $role) {
                $staffId = $trip->{$role.'_id'};
                if ($staffId) {
                    $this->insertRecord(['shift_block_id' => null, 'trip_id' => $trip->trip_id], $role, (int) $staffId, collect([$trip]));
                }
            }
        }
    }

    private function insertRecord(array $key, string $role, int $staffId, $legs): void
    {
        $live = $legs->filter(fn ($leg) => $leg->status !== 'cancelled');
        $accepted = $live->isNotEmpty() && $live->every(fn ($leg) => $leg->{$role.'_accepted_at'} !== null);
        $acceptedAt = $accepted ? $live->max(fn ($leg) => $leg->{$role.'_accepted_at'}) : null;

        $latest = DB::table('trip_assignment_requests')
            ->whereIn('trip_id', $legs->pluck('trip_id'))
            ->where('role', $role)
            ->where('staff_company_user_id', $staffId)
            ->orderByDesc('id')
            ->first();

        $status = 'pending';
        $extra = [];
        if ($accepted) {
            $status = 'accepted';
        } elseif ($latest && in_array($latest->status, ['for_approval', 'rejected'], true)) {
            $status = $latest->status;
            $extra = [
                'reason_code' => $latest->reason_code,
                'reason_text' => $latest->reason_text,
                'requested_at' => $latest->created_at,
                'decided_by_company_user_id' => $latest->decided_by_company_user_id,
                'decided_at' => $latest->decided_at,
            ];
        }

        DB::table('shift_block_assignments')->insert($key + [
            'role' => $role,
            'staff_company_user_id' => $staffId,
            'status' => $status,
            'accepted_at' => $acceptedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ] + $extra);
    }
};
