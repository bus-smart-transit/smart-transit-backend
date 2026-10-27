<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin IdeHelperTicket
 *
 * @property int $ticket_id
 * @property int $fleet_route_id
 * @property int $trip_id
 * @property int|null $fare_rule_id
 * @property int $payment_id
 * @property int|null $passenger_id
 * @property string $ticket_uuid
 * @property string $status
 * @property numeric $amount
 * @property Carbon|null $boarded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $seat_type
 * @property numeric|null $distance_km
 * @property int|null $origin_stop_id
 * @property int|null $destination_stop_id
 * @property Carbon|null $alighted_at
 * @property-read Stop|null $destinationStop
 * @property-read FareRule|null $fareRule
 * @property-read FleetRoute $fleetRoute
 * @property-read Stop|null $originStop
 * @property-read PassengerUser|null $passenger
 * @property-read Payment $payment
 * @property-read Trip $trip
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereAlightedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereBoardedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereDestinationStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereDistanceKm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereFareRuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereFleetRouteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereOriginStopId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket wherePassengerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereSeatType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereTicketUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereUpdatedAt($value)
 *
 * @mixin \Eloquent
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
        'origin_stop_id',
        'destination_stop_id',
        'origin_lat',
        'origin_lng',
        'destination_lat',
        'destination_lng',
        'destination_label',
        'alighting_point_id',
        'boarding_distance_m',
        'rounding_mode',
        'journey_leg',
        'alighting_distance_m',
        'tracking_token_hash',
        'tracking_token_enc',
        'discount_type_key',
        'discount_label',
        'discount_rate',
        'original_amount',
        'discount_amount',
        'discount_id_checked_at',
    ];

    // The tracking token is a bearer secret: never serialised with a ticket. Owners get it only
    // through the explicit `tracking_token` attribute the ticket service sets for them.
    protected $hidden = ['tracking_token_hash', 'tracking_token_enc'];

    protected function casts(): array
    {
        return [
            'tracking_token_enc' => 'encrypted',
            'boarded_at' => 'datetime',
            'alighted_at' => 'datetime',
            'origin_lat' => 'float',
            'origin_lng' => 'float',
            'destination_lat' => 'float',
            'destination_lng' => 'float',
            'discount_rate' => 'float',
            'original_amount' => 'float',
            'discount_amount' => 'float',
            'discount_id_checked_at' => 'datetime',
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

    /** Batch 28 Stage 3, item 10: the designated drop-off point this ticket was sold for (null for a stop or a free pin). */
    public function alightingPoint()
    {
        return $this->belongsTo(AlightingPoint::class, 'alighting_point_id');
    }

    public function originStop()
    {
        return $this->belongsTo(Stop::class, 'origin_stop_id');
    }

    public function destinationStop()
    {
        return $this->belongsTo(Stop::class, 'destination_stop_id');
    }
}
