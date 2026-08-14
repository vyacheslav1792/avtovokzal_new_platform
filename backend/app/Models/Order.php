<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * Шаблон идентификатора заказа для маршрутов: uuid у покупок нового сайта
     * и 30 hex-символов у импортированных из legacy site.ORDERS.
     */
    public const ID_PATTERN = '[0-9a-fA-F][0-9a-fA-F-]{29,35}';

    protected $casts = [
        'passengers' => 'array',
        'legacy' => 'array',
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

    /** Возвраты билетов этого заказа. */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Покупатель, если заказ оформлен из личного кабинета. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAYMENT_REGISTERED = 'payment_registered';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';
}
