<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperStop
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
        'longitude',
        'latitude'
    ];

    public function routeStops()
    {
        return $this->hasMany(RouteStop::class, 'stop_id', 'stop_id');
    }
}
