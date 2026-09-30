<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @mixin IdeHelperStop
 * @property int $stop_id
 * @property string $stop_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RouteStop> $routeStops
 * @property-read int|null $route_stops_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereStopName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Stop whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Stop extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'stops';
    protected $primaryKey = 'stop_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'stop_name',
        'stop_code',
        'longitude',
        'latitude',
        'geofence_radius_m',
        'municipality',
    ];

    protected static function booted(): void
    {
        static::creating(function (Stop $stop) {
            if (blank($stop->stop_code)) {
                $stop->stop_code = static::makeUniqueCode((string) $stop->stop_name);
            }
        });
    }

    /** A URL-safe code from the stop name, made unique with -2, -3, ... when taken. */
    public static function makeUniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'stop', 56, '');
        $code = $base;
        for ($i = 2; static::where('stop_code', $code)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        return $code;
    }

    public function routeStops()
    {
        return $this->hasMany(RouteStop::class, 'stop_id', 'stop_id');
    }
}
