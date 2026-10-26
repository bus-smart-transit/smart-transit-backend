<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Passenger In (2026-10-26): one passenger who boarded between stops and has not paid yet. See
 * App\Services\PendingBoardingService.
 *
 * @property int $id
 * @property int $trip_id
 * @property int $conductor_company_user_id
 * @property int|null $conductor_shift_log_id
 * @property int $stop_id
 * @property int $raw_distance_m
 * @property Carbon $checked_in_at
 * @property string $status
 * @property int|null $ticket_id
 */
class PendingBoarding extends Model
{
    public const PENDING = 'pending';

    public const SOLD = 'sold';

    public const UNPAID = 'unpaid';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'trip_id', 'conductor_company_user_id', 'conductor_shift_log_id', 'stop_id', 'raw_distance_m',
        'latitude', 'longitude', 'checked_in_at', 'status', 'ticket_id', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'closed_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function stop()
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'stop_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
