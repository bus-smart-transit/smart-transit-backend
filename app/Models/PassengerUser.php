<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
