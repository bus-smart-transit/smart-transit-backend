<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch 46: a bus company. Its Operators, Drivers and Chauffeurs are company_users rows with this company_id. The
 * Admin sets the name (decided 2026-11-06); Operators only see it.
 *
 * @property int $company_id
 * @property string $company_name
 */
class Company extends Model
{
    protected $primaryKey = 'company_id';

    /** 2026-11-25: baggage_fee_* are the Operator's baggage prices by size (null = the default, App\Support\BaggageFee). */
    protected $fillable = ['company_name', 'baggage_fee_small', 'baggage_fee_medium', 'baggage_fee_large'];

    public function staff(): HasMany
    {
        return $this->hasMany(StaffUser::class, 'company_id', 'company_id');
    }
}
