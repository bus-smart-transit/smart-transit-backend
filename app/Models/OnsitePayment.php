<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperOnsitePayment
 * @property int $onsite_payment_id
 * @property int $payment_id
 * @property int $conductor_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\StaffUser $conductor
 * @property-read \App\Models\Payment $payment
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereConductorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereOnsitePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnsitePayment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class OnsitePayment extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'onsite_payment';

    protected $primaryKey = 'onsite_payment_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'payment_id',
        'conductor_id',
    ];

    /**
     * Get the conductor that owns this record.
     */
    public function conductor()
    {
        return $this->belongsTo(StaffUser::class, 'conductor_id');
    }

    /**
     * Get the payment that owns this record.
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
