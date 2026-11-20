<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin IdeHelperRewardTransaction
 *
 * @property int $reward_transaction_id
 * @property int $passenger_id
 * @property int|null $payment_id
 * @property float $points
 * @property string $type
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PassengerUser $passenger
 * @property-read Payment|null $payment
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction wherePassengerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction wherePoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereRewardTransactionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RewardTransaction whereUpdatedAt($value)
 *
 * @mixin \Eloquent
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

    /** 2026-11-20: earned points have two decimals (RewardService::pointsFor); redeemed points stay whole. */
    protected function casts(): array
    {
        return ['points' => 'float'];
    }

    public function passenger()
    {
        return $this->belongsTo(PassengerUser::class, 'passenger_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
