<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftBlock extends Model
{
    protected $primaryKey = 'shift_block_id';

    // Batch 29: what a block IS (from its legs) and which section it belongs to, next to the stored workflow status.
    protected $appends = ['lifecycle_status', 'schedule_section'];

    protected $fillable = [
        'fleet_id',
        'driver_id',
        'conductor_id',
        'company_user_id',
        'block_type',
        'status',
        'audit_status',
        'scheduled_date',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
        ];
    }

    public function fleet()
    {
        return $this->belongsTo(Fleet::class, 'fleet_id', 'fleet_id');
    }

    public function driver()
    {
        return $this->belongsTo(StaffUser::class, 'driver_id', 'company_user_id');
    }

    public function conductor()
    {
        return $this->belongsTo(StaffUser::class, 'conductor_id', 'company_user_id');
    }

    /**
     * The owning Operator — mirrors Trip::companyUser().
     */
    public function companyUser()
    {
        return $this->belongsTo(StaffUser::class, 'company_user_id', 'company_user_id');
    }

    /** One status for every portal: from the leg statuses, never the stored workflow column (App\Support\ScheduleStatus). */
    public function getLifecycleStatusAttribute(): string
    {
        return \App\Support\ScheduleStatus::forLegs($this->legs, (string) ($this->attributes['status'] ?? 'scheduled'));
    }

    public function getScheduleSectionAttribute(): string
    {
        return \App\Support\ScheduleStatus::section($this->scheduled_date?->toDateString(), $this->lifecycle_status);
    }

    /**
     * This block's legs — each an existing `trips` row (no separate
     * shift_block_legs table, per Batch 18's schema decision).
     */
    public function legs()
    {
        return $this->hasMany(Trip::class, 'shift_block_id', 'shift_block_id')->orderBy('trip_id');
    }
}
