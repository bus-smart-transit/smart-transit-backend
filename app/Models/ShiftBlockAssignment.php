<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 28 (item 1): the block-level assignment decision for one role on one shift
 * block (or on a standalone trip, which is a block of one leg and is keyed by trip_id).
 *
 * @property int $id
 * @property int|null $shift_block_id
 * @property int|null $trip_id
 * @property string $role driver | conductor
 * @property int $staff_company_user_id
 * @property string $status pending | accepted | for_approval | rejected | released
 */
class ShiftBlockAssignment extends Model
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const FOR_APPROVAL = 'for_approval';
    public const REJECTED = 'rejected';
    public const RELEASED = 'released';

    protected $table = 'shift_block_assignments';

    protected $fillable = [
        'shift_block_id',
        'trip_id',
        'role',
        'staff_company_user_id',
        'status',
        'accepted_at',
        'reason_code',
        'reason_text',
        'requested_at',
        'decided_by_company_user_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function shiftBlock(): BelongsTo
    {
        return $this->belongsTo(ShiftBlock::class, 'shift_block_id', 'shift_block_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_company_user_id', 'company_user_id');
    }
}
