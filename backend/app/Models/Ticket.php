<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Оформленный билет заказа (одна строка — один пассажирский билет).
 * Багажные квитанции билета — в jsonb baggage_tickets: их ≤5 на билет,
 * поиска по их номерам нет, отдельная таблица не нужна.
 */
class Ticket extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'baggage_tickets' => 'array',
        'legacy' => 'array',
        'place' => 'int',
        'baggage' => 'int',
        'doc_type' => 'int',
        'citizenship' => 'int',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
