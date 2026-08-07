<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $online_payment_id
 * @property int $payment_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Payment $payment
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment whereOnlinePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnlinePayment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class OnlinePayment extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'online_payment';

    protected $primaryKey = 'online_payment_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'payment_id',
    ];

    /**
     * Get the payment that owns this record.
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
