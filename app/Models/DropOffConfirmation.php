<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 2026-10-27 (§7): a destination-group drop-off the Chauffeur confirmed, with the tickets it included. */
class DropOffConfirmation extends Model
{
    protected $fillable = [
        'trip_id',
        'conductor_company_user_id',
        'destination_key',
        'destination_label',
        'destination_stop_id',
        'ticket_ids',
        'ticket_count',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'ticket_ids' => 'array',
            'ticket_count' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }
}
