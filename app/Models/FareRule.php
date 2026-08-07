<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperFareRule
 * @property int $fare_rule_id
 * @property int $fleet_id
 * @property float $base_fare
 * @property float $fare_per_km
 * @property string $status
 * @property string $seat_type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Fleet $fleet
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereBaseFare($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereFarePerKm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereFareRuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereFleetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereSeatType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FareRule whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class FareRule extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'fare_rules';

    protected $primaryKey = 'fare_rule_id';
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'fleet_id',
        'base_fare',
        'fare_per_km',
        'status',
        'seat_type',
    ];

    /**
     * Get the fleet that owns this record.
     */
    public function fleet()
    {
        return $this->belongsTo(Fleet::class, 'fleet_id');
    }
}
