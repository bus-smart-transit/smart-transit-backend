<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fleet extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'fleets';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'fleet_id',
        'company_user_id',
        'plate_number',
        'capacity',
        'seated_capacity',
        'standing_capacity',
        'status',
    ];

    /**
     * Get the companyUser that owns this record.
     */
    public function companyUser()
    {
        return $this->belongsTo(CompanyUser::class, 'company_user_id');
    }
}
