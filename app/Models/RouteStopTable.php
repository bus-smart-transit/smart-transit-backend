<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteStopTable extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'route_stop_table';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'stop_id',
        'route_id',
        'stop_order',
        'distance_from_origin_km',
    ];

    /**
     * Get the route that owns this record.
     */
    public function route()
    {
        return $this->belongsTo(\App\Models\Route::class, 'route_id');
    }

    /**
     * Get the stop that owns this record.
     */
    public function stop()
    {
        return $this->belongsTo(\App\Models\Stop::class, 'stop_id');
    }
}
