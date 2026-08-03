<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequest extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'success' => 'boolean',
        'created_at' => 'datetime',
    ];
}
