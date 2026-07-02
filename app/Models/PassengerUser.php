<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PassengerUser extends Model
{
    use HasUuids;

    protected $primaryKey = 'passenger_id';

    // Keep incrementing true since your primary key is an auto-incrementing integer id
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

    /**
     * Set the custom column name for your system UUIDs
     */
    public function uniqueIds(): array
    {
        return ['passenger_uuid'];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}