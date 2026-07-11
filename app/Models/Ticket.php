<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperTicket
 */
class Ticket extends Model
{
    protected $table = 'tickets';
    protected $primaryKey = 'ticket_id';

    protected $fillable = [
        'fleet_route_id',
        'trip_id',
        'fare_rule_id',
        'distance_km',
        'payment_id',
        'passenger_id',
        'ticket_uuid',
        'status',
        'amount',
        'boarded_at',
        'seat_type',
    ];

    protected function casts(): array
    {
        return [
            'boarded_at' => 'datetime',
        ];
    }

    // Which fleet's rate was used to price this ticket. Fares are computed
    // on the fly (no fare_matrix table) — this is the only fare-provenance
    // reference a ticket carries now.
    public function fareRule()
    {
        return $this->belongsTo(FareRule::class, 'fare_rule_id');
    }

    public function fleetRoute()
    {
        return $this->belongsTo(FleetRoute::class, 'fleet_route_id');
    }

    public function passenger()
    {
        return $this->belongsTo(PassengerUser::class, 'passenger_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }
}