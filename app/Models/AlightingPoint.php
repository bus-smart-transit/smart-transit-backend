<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 28 Stage 3, item 10: a named place on a route (barangay, intersection or landmark) where a passenger may get
 * off outside the official stops. The names and coordinates are entered by the team (from the operator's fare matrix),
 * never built into the code. The distances along the route are computed by the server from the route line.
 */
class AlightingPoint extends Model
{
    public const KINDS = ['barangay', 'intersection', 'landmark'];

    protected $table = 'alighting_points';

    protected $fillable = [
        'route_id', 'name', 'kind', 'municipality', 'latitude', 'longitude', 'distance_along_outbound_m',
        'distance_along_reverse_m', 'snap_distance_m', 'is_active', 'source', 'note', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
            'distance_along_outbound_m' => 'integer',
            'distance_along_reverse_m' => 'integer',
            'snap_distance_m' => 'float',
        ];
    }

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    /** Distance along the route in the direction a leg runs (metres from that leg's start), or null when unknown. */
    public function distanceForDirection(string $direction): ?int
    {
        return $direction === 'reverse' ? $this->distance_along_reverse_m : $this->distance_along_outbound_m;
    }
}
