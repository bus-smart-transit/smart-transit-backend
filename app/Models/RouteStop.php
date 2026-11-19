<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperRouteStop
 * @property int $route_stop_id
 * @property int $stop_id
 * @property int $route_id
 * @property int $stop_order
 * @property numeric $distance_from_origin_km
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Route $route
 * @property-read \App\Models\Stop $stop
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereDistanceFromOriginKm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereRouteStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereStopOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RouteStop whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class RouteStop extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'route_stop';
    // DB column is `id` (created by $table->id() in migration).
    // Previously declared as 'route_stop_id' which caused Postgres to fail
    // with "column route_stop_id does not exist" on insert.
    protected $primaryKey = 'id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'route_id',
        'stop_id',
        'stop_order',
        'distance_from_origin_km',
        'distance_from_origin_m',
        'stop_type',
        'snapped_latitude',
        'snapped_longitude',
        'scheduled_offset_min',
        'served_by_express',
        'express_offset_min',
    ];

    protected function casts(): array
    {
        return [
            'served_by_express' => 'boolean',
        ];
    }

    public const STOP_TYPES = ['terminal', 'stop', 'pass_through'];

    protected static function booted(): void
    {
        // Keep the metre column in step with the km source of truth.
        static::saving(function (RouteStop $routeStop) {
            if ($routeStop->isDirty('distance_from_origin_km') && $routeStop->distance_from_origin_km !== null) {
                $routeStop->distance_from_origin_m = (int) round((float) $routeStop->distance_from_origin_km * 1000);
            }
        });
    }

    /**
     * Get the route that owns this record.
     */
    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    /**
     * Get the stop that owns this record.
     */
    public function stop()
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'stop_id');
    }

}
