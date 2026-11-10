<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 58: an alert a Driver or Chauffeur raised on their trip ("Alert operator"). Rules live in TripAlertService.
 *
 * @property int $alert_id
 * @property int $trip_id
 * @property int $raised_by_company_user_id
 * @property string $raised_role
 * @property string $type
 * @property string|null $note
 * @property string $status
 */
class TripAlert extends Model
{
    public const OPEN = 'open';

    public const HANDLED = 'handled';

    public const CANCELLED = 'cancelled';

    protected $primaryKey = 'alert_id';

    protected $fillable = [
        'trip_id',
        'raised_by_company_user_id',
        'raised_role',
        'type',
        'note',
        'latitude',
        'longitude',
        'location_source',
        'status',
        'cancelled_at',
        'handled_by_company_user_id',
        'handled_at',
        'handled_note',
        'passengers_told_by_company_user_id',
        'passengers_told_at',
        'passengers_told_count',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'cancelled_at' => 'datetime',
            'handled_at' => 'datetime',
            'passengers_told_at' => 'datetime',
            'passengers_told_count' => 'integer',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'raised_by_company_user_id', 'company_user_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'handled_by_company_user_id', 'company_user_id');
    }
}
