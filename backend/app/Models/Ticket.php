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

    /**
     * Багажные бланки билета как [{serial, number}, …].
     *
     * На станции это самостоятельные билеты категории 9, поэтому и при
     * возврате они аннулируются отдельными номерами — отсюда общий разбор
     * на оба возврата (по коду и из АИС).
     *
     * @return array<int, array{serial: string, number: string}>
     */
    public function baggageBlanks(): array
    {
        $blanks = [];

        foreach ((array) ($this->baggage_tickets ?? []) as $bag) {
            if (! is_array($bag) || empty($bag['serial']) || empty($bag['number'])) {
                continue;
            }

            $blanks[] = ['serial' => (string) $bag['serial'], 'number' => (string) $bag['number']];
        }

        return $blanks;
    }
}
