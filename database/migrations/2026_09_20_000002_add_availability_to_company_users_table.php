<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Batch 15, Items 4/9: "Available" status is a distinct concept from
     * shift/pairing state — a Driver or Conductor can mark themselves
     * available for extra/ad-hoc assignment even while off-shift. Modeled
     * as a simple column pair on company_users (no dedicated history table
     * — no audit/history requirement was specified for this feature; the
     * generic audit_logs table already covers a change-event trail if
     * ever needed, via AuditLogger, without a bespoke table).
     */
    public function up(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->boolean('is_available')->default(false)->after('address');
            $table->timestamp('available_since')->nullable()->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropColumn(['is_available', 'available_since']);
        });
    }
};
