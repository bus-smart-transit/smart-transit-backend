<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FareRule extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'fare_rules';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'fare_rule_id',
        'fleet_id',
        'base_fare',
        'fare_per_km',
        'status',
        'seat_type',
    ];

    /**
     * Get the fleet that owns this record.
     */
    public function fleet()
    {
        return $this->belongsTo(\App\Models\Fleet::class, 'fleet_id');
    }
}
