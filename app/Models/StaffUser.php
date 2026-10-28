<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin IdeHelperStaffUser
 *
 * @property int $company_user_id
 * @property string $company_user_uuid
 * @property int $user_id
 * @property string $phone_num
 * @property string $name
 * @property string $address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
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
 *
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
        'is_available',
        'available_since',
        'gender',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'available_since' => 'datetime',
        ];
    }

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
