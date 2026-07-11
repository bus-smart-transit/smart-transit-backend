<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperOnlinePayment
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
        'passenger_id',
        'payment_id',
    ];

    /**
     * Get the passenger that owns this record.
     */
    public function passenger()
    {
        return $this->belongsTo(PassengerUser::class, 'passenger_id');
    }

    /**
     * Get the payment that owns this record.
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
