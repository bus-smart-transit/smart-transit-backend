<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'routes';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'route_id',
        'origin',
        'destination',
        'route_name',
    ];
}
