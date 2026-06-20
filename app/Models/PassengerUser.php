<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PassengerUser extends Model
{
    use HasUuids;

    protected $primaryKey = 'passenger_id';

    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'user_id',
        'name',
        'phone_num',
        'address',
        'reward_points',
    ];

    public function uniqueIds(): array
    {
        return ['passenger_uuid'];
    }
}
