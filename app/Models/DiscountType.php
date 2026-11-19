<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 2026-10-27: an onsite fare discount type (Student, Senior citizen, Person with disability). */
class DiscountType extends Model
{
    protected $fillable = ['key', 'label', 'rate', 'sort_order'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'sort_order' => 'integer',
        ];
    }
}
