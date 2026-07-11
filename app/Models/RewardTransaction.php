<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperRewardTransaction
 */
class RewardTransaction extends Model
{
    protected $table = 'reward_transactions';

    protected $primaryKey = 'reward_transaction_id';

    protected $fillable = [
        'passenger_id',
        'payment_id',
        'points',
        'type',
        'description',
    ];

    public function passenger()
    {
        return $this->belongsTo(PassengerUser::class, 'passenger_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}