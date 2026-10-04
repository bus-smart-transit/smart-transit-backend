<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 28 Stage 3, item 7: a configured peak-hour window for one route. See the migration for the rules.
 */
class RouteDurationFactor extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_MEASURED = 'measured';

    protected $table = 'route_duration_factors';

    protected $fillable = ['route_id', 'direction', 'day_type', 'start_time', 'end_time', 'factor', 'source', 'note', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['factor' => 'float'];
    }

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }
}
