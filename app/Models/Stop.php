<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stop extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected $table = 'stops';

    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected $fillable = [
        'stop_id',
        'stop_name',
    ];
}
