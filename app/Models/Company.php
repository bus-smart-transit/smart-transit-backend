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

    protected $fillable = ['company_name'];

    public function staff(): HasMany
    {
        return $this->hasMany(StaffUser::class, 'company_id', 'company_id');
    }
}
