<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'passengers' => 'array',
        'date_start' => 'date:Y-m-d',
        'arrival_at' => 'datetime',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** Оформленные билеты (появляются при завершении заказа). */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('position');
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAYMENT_REGISTERED = 'payment_registered';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';
}
