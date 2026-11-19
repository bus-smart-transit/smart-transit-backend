<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 28 Stage 3, item 10: an area where a custom drop-off is NOT available. Today only the 'route_segment' type
 * exists: the stretch of ONE route between two of its route stops, in both directions. Nothing here names a place:
 * the name, the note and the source are whatever the team typed.
 */
class CustomDropoffRestriction extends Model
{
    public const AREA_ROUTE_SEGMENT = 'route_segment';

    public const APPLIES_CUSTOM_DROPOFF = 'custom_dropoff';

    protected $table = 'custom_dropoff_restrictions';

    protected $fillable = [
        'name', 'area_type', 'route_id', 'from_route_stop_id', 'to_route_stop_id', 'from_distance_m', 'to_distance_m',
        'applies_to', 'is_active', 'needs_review', 'review_reason', 'source', 'note', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
            'from_distance_m' => 'integer',
            'to_distance_m' => 'integer',
        ];
    }

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function fromRouteStop()
    {
        return $this->belongsTo(RouteStop::class, 'from_route_stop_id', 'id');
    }

    public function toRouteStop()
    {
        return $this->belongsTo(RouteStop::class, 'to_route_stop_id', 'id');
    }
}
