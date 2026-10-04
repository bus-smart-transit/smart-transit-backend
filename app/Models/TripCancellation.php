<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 28 Stage 3, item 6: the record of an operator cancelling a trip. One row per trip. Timestamps are UTC.
 */
class TripCancellation extends Model
{
    public const STAGE_BEFORE_DEPARTURE = 'before_departure';
    public const STAGE_MID_ROUTE = 'mid_route';

    protected $table = 'trip_cancellations';

    protected $fillable = [
        'trip_id', 'cancelled_by_user_id', 'actor_role', 'reason', 'stage', 'cancelled_at',
        'last_point_distance_m', 'last_point_source', 'last_point_stop_id', 'last_point_at',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_at' => \App\Casts\UtcDateTime::class,
            'last_point_at' => \App\Casts\UtcDateTime::class,
            'last_point_distance_m' => 'integer',
        ];
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function resolutions()
    {
        return $this->hasMany(TicketResolution::class, 'trip_cancellation_id');
    }
}
