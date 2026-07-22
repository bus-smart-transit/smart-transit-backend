<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperPassengerUser
 * @property int $passenger_id
 * @property string $passenger_uuid
 * @property int $user_id
 * @property string $name
 * @property string $phone_num
 * @property string $address
 * @property float $reward_points
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $birthdate
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereBirthdate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser wherePassengerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser wherePassengerUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser wherePhoneNum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereRewardPoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PassengerUser whereUserId($value)
 * @mixin \Eloquent
 */
class PassengerUser extends Model
{
    protected $primaryKey = 'passenger_id';

    // Standard int PK — no HasUuids trait needed since passenger_uuid
    // is generated manually in the repository, not by this trait
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'passenger_uuid',
        'name',
        'birthdate',
        'phone_num',
        'address',
        'reward_points',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
