<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FareMatrix extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'fare_matrix';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'origin_stop_id',
        'destination_stop_id',
        'amount',
        'seat_type',
        'status',
        'fleet_id',
        'fare_rule_id',
    ];

    /**
     * Get the destinationStop that owns this record.
     */
    public function destinationStop()
    {
        return $this->belongsTo(Stop::class, 'destination_stop_id');
    }

    /**
     * Get the fareRule that owns this record.
     */
    public function fareRule()
    {
        return $this->belongsTo(FareRule::class, 'fare_rule_id');
    }

    /**
     * Get the fleet that owns this record.
     */
    public function fleet()
    {
        return $this->belongsTo(Fleet::class, 'fleet_id');
    }

    /**
     * Get the originStop that owns this record.
     */
    public function originStop()
    {
        return $this->belongsTo(Stop::class, 'origin_stop_id');
    }
}
