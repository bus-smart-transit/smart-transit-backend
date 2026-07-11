<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperPayment
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'amount',
        'payment_created',
        'transaction_reference',
        'payment_method',
        'payment_channel',
        'status',
        'payment_uuid',
        'is_valid',
        'guest_email',
        'gateway_reference',
        'payment_intent_id',
        'items_payload',
        'hold_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_created' => 'datetime',
            'hold_expires_at' => 'datetime',
            // Stores the reserved items (trip_id, seat_type, stop ids OR
            // coordinates, fare_rule_id, distance_km, amount) locked in at
            // checkout time, so the webhook can create the actual ticket
            // rows later without re-running any pricing logic.
            'items_payload' => 'array',
        ];
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'payment_id', 'payment_id');
    }

    public function onlinePayment()
    {
        return $this->hasOne(OnlinePayment::class, 'payment_id', 'payment_id');
    }

    public function onsitePayment()
    {
        return $this->hasOne(OnsitePayment::class, 'payment_id', 'payment_id');
    }
}