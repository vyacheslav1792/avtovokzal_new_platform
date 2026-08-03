<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketSale extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'tickets' => 'array',
        'raw_response' => 'array',
        'date_start' => 'date:Y-m-d',
    ];
}
