<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverShiftLog extends Model
{
    protected $table = 'driver_shift_logs';

    protected $primaryKey = 'shift_log_id';

    protected $fillable = [
        'driver_company_user_id',
        'trip_id',
        'fleet_id',
        'shift_date',
        'started_at',
        'ended_at',
        'start_source',
        'end_source',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function driver()
    {
        return $this->belongsTo(StaffUser::class, 'driver_company_user_id', 'company_user_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function fleet()
    {
        return $this->belongsTo(Fleet::class, 'fleet_id', 'fleet_id');
    }
}
