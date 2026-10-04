<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 28 Stage 3, item 6: how one cancelled ticket was settled. One row per ticket (unique ticket_id): a rebook
 * onto a later departure, or a refund (gateway reversal for a digital ticket, a recorded cash entry for a cash ticket).
 */
class TicketResolution extends Model
{
    public const REBOOK = 'rebook';
    public const REFUND = 'refund';

    public const PENDING = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const NEEDS_REVIEW = 'needs_review';

    public const METHOD_GATEWAY = 'gateway';
    public const METHOD_CASH = 'cash';

    protected $table = 'ticket_resolutions';

    protected $fillable = [
        'ticket_id', 'trip_cancellation_id', 'resolution', 'status', 'method', 'amount', 'fare_paid', 'ridden_fare', 'basis',
        'actor_user_id', 'actor_role', 'on_behalf', 'note', 'rebooked_ticket_id', 'gateway_payment_id', 'gateway_refund_id',
        'gateway_status', 'failure_reason', 'not_before', 'requested_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'basis' => 'array',
            'on_behalf' => 'boolean',
            'amount' => 'float',
            'fare_paid' => 'float',
            'ridden_fare' => 'float',
            'not_before' => \App\Casts\UtcDateTime::class,
            'requested_at' => \App\Casts\UtcDateTime::class,
            'completed_at' => \App\Casts\UtcDateTime::class,
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function cancellation()
    {
        return $this->belongsTo(TripCancellation::class, 'trip_cancellation_id');
    }
}
