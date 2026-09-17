<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lightweight, non-targeted notification surfaced to the Operator portal
 * (S2, Batch 12) — e.g. a driver/conductor declining an assigned trip.
 * Any user with role 'operator' or 'admin' can see/dismiss these; they are
 * not addressed to a specific recipient.
 *
 * @property int $id
 * @property int $trip_id
 * @property string $type
 * @property string $title
 * @property string $message
 * @property int|null $actor_company_user_id
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Trip $trip
 * @property-read \App\Models\StaffUser|null $actor
 */
class TripNotification extends Model
{
    protected $table = 'trip_notifications';

    protected $fillable = [
        'trip_id',
        'type',
        'title',
        'message',
        'actor_company_user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'actor_company_user_id', 'company_user_id');
    }
}
