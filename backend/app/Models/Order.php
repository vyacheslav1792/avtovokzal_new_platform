<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'passengers' => 'array',
        'tickets' => 'array',
        'date_start' => 'date:Y-m-d',
        'arrival_at' => 'datetime',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAYMENT_REGISTERED = 'payment_registered';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';
}
