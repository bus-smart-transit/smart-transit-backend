<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver's or Chauffeur's request to be released from a trip assignment.
 * Assignment-level status (D6), independent of the trip lifecycle.
 *
 * @property int $id
 * @property int $trip_id
 * @property string $role
 * @property int $staff_company_user_id
 * @property string|null $reason_code
 * @property string|null $reason_text
 * @property string $status for_approval | approved | rejected
 */
class TripAssignmentRequest extends Model
{
    public const FOR_APPROVAL = 'for_approval';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'trip_assignment_requests';

    protected $fillable = [
        'trip_id',
        'role',
        'staff_company_user_id',
        'reason_code',
        'reason_text',
        'status',
        'decided_by_company_user_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
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
