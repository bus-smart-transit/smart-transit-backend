<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyUser extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'company_users';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'company_user_id',
        'company_user_uuid',
        'user_id',
        'phone_num',
        'name',
        'address',
    ];

    /**
     * Get the user that owns this record.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
