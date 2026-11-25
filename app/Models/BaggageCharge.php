<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 2026-11-25 (user): one baggage payment in cash to the Chauffeur on board (see the baggage_charges migration).
 *
 * @property int $baggage_charge_id
 * @property int $trip_id
 * @property int $ticket_id
 * @property int|null $conductor_company_user_id
 * @property int|null $conductor_shift_log_id
 * @property string $size
 * @property string $amount
 * @property string|null $stub_number
 * @property Carbon $collected_at
 * @property Carbon|null $voided_at
 */
class BaggageCharge extends Model
{
    protected $primaryKey = 'baggage_charge_id';

    protected $fillable = [
        'trip_id',
        'ticket_id',
        'conductor_company_user_id',
        'conductor_shift_log_id',
        'size',
        'amount',
        'stub_number',
        'collected_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'collected_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
