<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 29, cash step 3: what was handed over for one Conductor shift, and whether the Operator confirmed it.
 * See the migration for the meaning of the columns.
 */
class CashRemittance extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const FLAGGED = 'flagged';

    public const RESOLVED = 'resolved';

    public const DECLARED = 'declared';

    public const NONE = 'none';

    protected $table = 'cash_remittances';

    protected $fillable = [
        'conductor_shift_log_id', 'conductor_company_user_id', 'operator_company_user_id', 'status', 'declaration', 'declared_cash', 'declared_at',
        'expected_cash', 'confirmed_amount', 'confirmed_by_user_id', 'confirmed_at', 'note',
        'variance', 'tolerance_applied', 'flagged_at', 'resolved_by_user_id', 'resolved_at', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'declared_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'confirmed_amount' => 'decimal:2',
            'variance' => 'decimal:2',
            'tolerance_applied' => 'decimal:2',
            'flagged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'declared_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function shift()
    {
        return $this->belongsTo(ConductorShiftLog::class, 'conductor_shift_log_id', 'shift_log_id');
    }
}
