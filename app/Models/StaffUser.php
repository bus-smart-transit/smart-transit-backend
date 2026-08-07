<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @mixin IdeHelperStaffUser
 * @property int $company_user_id
 * @property string $company_user_uuid
 * @property int $user_id
 * @property string $phone_num
 * @property string $name
 * @property string $address
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereCompanyUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereCompanyUserUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser wherePhoneNum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StaffUser whereUserId($value)
 * @mixin \Eloquent
 */
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
        'birth_date',
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
