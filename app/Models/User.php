<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'username',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getIdAttribute()
    {
        return $this->user_id;
    }

    public function passengerProfile(): HasOne
    {
        return $this->hasOne(PassengerUser::class, 'user_id', 'user_id');
    }

    public function companyProfile(): HasOne
    {
        return $this->hasOne(StaffUser::class, 'user_id', 'user_id');
    }
}
