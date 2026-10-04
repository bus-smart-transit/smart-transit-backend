<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RouteStop> $routeStops
 * @mixin IdeHelperRoute
 * @property int $route_id
 * @property string $origin
 * @property string $destination
 * @property string $route_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FleetRoute> $fleetRoutes
 * @property-read int|null $fleet_routes_count
 * @property-read int|null $route_stops_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereRouteName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Route whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Route extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'routes';
    protected $primaryKey = 'route_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'origin',
        'destination',
        'route_name',
        'allow_custom_dropoff',
        'custom_dropoff_mode',
    ];

    protected $casts = [
        'allow_custom_dropoff' => 'boolean',
    ];

    public const MODE_OFF = 'off';
    public const MODE_DESIGNATED = 'designated_points';
    public const MODE_FREE_PIN = 'free_pin';
    public const MODES = [self::MODE_OFF, self::MODE_DESIGNATED, self::MODE_FREE_PIN];

    /**
     * Batch 28 Stage 3, item 10: routes.custom_dropoff_mode is the setting and routes.allow_custom_dropoff (the older
     * on/off flag, still read in many places) always follows it. Writing either keeps the other in step: the flag on
     * means "any mode but off" (free pin unless the mode says designated points), the flag off means mode off.
     */
    protected static function booted(): void
    {
        static::saving(function (Route $route) {
            $mode = (string) ($route->custom_dropoff_mode ?? self::MODE_OFF);
            if (! in_array($mode, self::MODES, true)) {
                $mode = self::MODE_OFF;
            }

            if ($route->isDirty('custom_dropoff_mode') && ! $route->isDirty('allow_custom_dropoff')) {
                $route->allow_custom_dropoff = $mode !== self::MODE_OFF;
            } elseif ($route->isDirty('allow_custom_dropoff')) {
                $mode = $route->allow_custom_dropoff
                    ? ($mode === self::MODE_OFF ? self::MODE_FREE_PIN : $mode)
                    : self::MODE_OFF;
            } elseif ($route->allow_custom_dropoff && $mode === self::MODE_OFF) {
                $mode = self::MODE_FREE_PIN;
            } elseif (! $route->allow_custom_dropoff) {
                $mode = self::MODE_OFF;
            }
            $route->custom_dropoff_mode = $mode;
        });
    }

    /** The mode in force, whichever way the route was last written. */
    public function dropoffMode(): string
    {
        if (! $this->allow_custom_dropoff) {
            return self::MODE_OFF;
        }

        return $this->custom_dropoff_mode === self::MODE_DESIGNATED ? self::MODE_DESIGNATED : self::MODE_FREE_PIN;
    }

    // Canonical geometry is served by GET /routes/{id}/geometry as GeoJSON;
    // the raw column (hex EWKB on Postgres) must never leak into payloads.
    protected $hidden = ['route_geometry'];
    public function routeStops()
    {
        return $this->hasMany(RouteStop::class, 'route_id', 'route_id')->orderBy('stop_order');
    }

    public function fleetRoutes()
    {
        return $this->hasMany(FleetRoute::class, 'route_id', 'route_id');
    }
}