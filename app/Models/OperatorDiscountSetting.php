<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 2026-10-27: an Operator's on/off choice for one discount type. No row means the type is on. */
class OperatorDiscountSetting extends Model
{
    protected $fillable = ['company_user_id', 'discount_type_id', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
