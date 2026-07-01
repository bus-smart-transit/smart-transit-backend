<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'tickets';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'ticket_id',
        'fleet_route_id',
        'trip_id',
        'fare_id',
        'payment_id',
        'passenger_id',
        'ticket_uuid',
        'status',
        'amount',
        'boarded_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
        'boarded_at' => 'datetime',
        ];
    }

    /**
     * Get the fare that owns this record.
     */
    public function fare()
    {
        return $this->belongsTo(\App\Models\FareMatrix::class, 'fare_id');
    }

    /**
     * Get the fleetRoute that owns this record.
     */
    public function fleetRoute()
    {
        return $this->belongsTo(\App\Models\FleetsRoute::class, 'fleet_route_id');
    }

    /**
     * Get the passenger that owns this record.
     */
    public function passenger()
    {
        return $this->belongsTo(\App\Models\PassengerUser::class, 'passenger_id');
    }

    /**
     * Get the payment that owns this record.
     */
    public function payment()
    {
        return $this->belongsTo(\App\Models\Payment::class, 'payment_id');
    }

    /**
     * Get the trip that owns this record.
     */
    public function trip()
    {
        return $this->belongsTo(\App\Models\Trip::class, 'trip_id');
    }
}
