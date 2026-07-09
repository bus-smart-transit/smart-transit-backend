<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    /** 
     * The attributes that are mass assignable from structural payloads.
     */
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
        'hold_expires_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_created' => 'datetime',
            'hold_expires_at' => 'datetime',
            'items_payload' => 'json'
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
