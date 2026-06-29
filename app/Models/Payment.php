<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'payments';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'payment_id',
        'amount',
        'payment_created',
        'transaction_reference',
        'payment_method',
        'payment_channel',
        'status',
        'payment_uuid',
        'is_valid',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
        'payment_created' => 'datetime',
        ];
    }
}
