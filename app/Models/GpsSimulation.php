<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Batch 49 item 4: one Super Admin GPS simulation of a trip that has departed (GpsSimulationService).
 *
 * @property int $id
 * @property int $trip_id
 * @property int $fleet_id
 * @property int $started_by_user_id
 * @property int $speed
 * @property string $status running | paused | stopped
 * @property float $along_m metres along the route line, in the trip's direction
 * @property Carbon|null $last_tick_at
 * @property Carbon $expires_at
 * @property Carbon|null $stopped_at
 * @property string|null $stop_reason
 */
class GpsSimulation extends Model
{
    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const STOPPED = 'stopped';

    protected $fillable = [
        'trip_id', 'fleet_id', 'started_by_user_id', 'speed', 'status', 'along_m', 'last_tick_at', 'expires_at', 'stopped_at', 'stop_reason',
    ];

    protected function casts(): array
    {
        return [
            'speed' => 'integer',
            'along_m' => 'float',
            'last_tick_at' => 'datetime',
            'expires_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
