<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $fleet_id
 * @property int|null $driver_id
 * @property int|null $conductor_id
 * @property string $pin_code
 * @property \Illuminate\Support\Carbon $pin_date
 * @property \Illuminate\Support\Carbon|null $driver_verified_at
 * @property \Illuminate\Support\Carbon|null $conductor_verified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\StaffUser|null $conductor
 * @property-read \App\Models\StaffUser|null $driver
 * @property-read \App\Models\Fleet $fleet
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereConductorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereConductorVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereDriverId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereDriverVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereFleetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin wherePinCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin wherePinDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FleetDailyPin whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class FleetDailyPin extends Model
{
    protected $table = 'fleet_daily_pins';

    protected $fillable = [
        'fleet_id',
        'driver_id',
        'conductor_id',
        'pin_code',
        'pin_date',
        'driver_verified_at',
        'conductor_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'pin_date'             => 'date',
            'driver_verified_at'   => 'datetime',
            'conductor_verified_at' => 'datetime',
        ];
    }

    public function fleet()
    {
        return $this->belongsTo(Fleet::class, 'fleet_id', 'fleet_id');
    }

    public function driver()
    {
        return $this->belongsTo(StaffUser::class, 'driver_id', 'company_user_id');
    }

    public function conductor()
    {
        return $this->belongsTo(StaffUser::class, 'conductor_id', 'company_user_id');
    }

    public function isBothVerified(): bool
    {
        return $this->driver_verified_at !== null && $this->conductor_verified_at !== null;
    }
}
