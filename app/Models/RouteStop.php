<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteStop extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'route_stop';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'route_id',
        'stop_order',
        'distance_from_origin_km',
    ];

    /**
     * Get the route that owns this record.
     */
    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id');
    }

    /**
     * Get the stop that owns this record.
     */
    public function stop()
    {
        return $this->belongsTo(Stop::class, 'stop_id');
    }
}
