<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Batch 75: a discount claimed online, checked by the Chauffeur on the bus (DiscountClaimService owns the rules). */
class DiscountClaim extends Model
{
    public const CLAIMED = 'claimed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const LAPSED = 'lapsed';

    public const REFUND_PENDING = 'pending';

    public const REFUND_SUCCEEDED = 'succeeded';

    public const REFUND_FAILED = 'failed';

    protected $fillable = [
        'ticket_id',
        'discount_type_key',
        'discount_label',
        'discount_rate',
        'status',
        'fare',
        'refund_amount',
        'refund_status',
        'gateway_payment_id',
        'gateway_refund_id',
        'gateway_status',
        'failure_reason',
        'not_before',
        'reviewed_by_user_id',
        'reviewed_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_rate' => 'float',
            'fare' => 'float',
            'refund_amount' => 'float',
            'not_before' => 'datetime',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }
}
