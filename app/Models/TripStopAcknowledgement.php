<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 2026-11-21 (user): one stop marked as reached on a trip (by the Driver, or by the GPS simulator for the demo).
 *
 * @property int $id
 * @property int $trip_id
 * @property int $stop_id
 * @property string $source
 * @property int|null $acknowledged_by_user_id
 * @property Carbon $acknowledged_at
 */
class TripStopAcknowledgement extends Model
{
    public const SOURCE_DRIVER = 'driver';

    public const SOURCE_SIMULATOR = 'simulator';

    protected $fillable = ['trip_id', 'stop_id', 'source', 'acknowledged_by_user_id', 'acknowledged_at'];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];
}
