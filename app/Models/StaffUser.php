<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class StaffUser extends Model
{
    use HasUuids;

    protected $primaryKey = 'company_user_id';

    // Keep incrementing true since your primary key is an auto-incrementing integer id
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'company_users';
    protected $fillable = [
        'user_id',
        'company_user_uuid',
        'phone_num',
        'name',
        'address',
    ];
    public function uniqueIds(): array
    {
        return ['company_user_uuid'];
    }
    /**
     * Get the user that owns this record.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
