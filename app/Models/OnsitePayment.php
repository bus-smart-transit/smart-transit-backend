<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnsitePayment extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'onsite_payment';

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
