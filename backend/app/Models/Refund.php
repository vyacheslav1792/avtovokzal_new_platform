<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Возврат. Видов три (`kind`), и они про разное:
 *  - `ticket`  — билет целиком: место освобождается на станции;
 *  - `baggage` — только багажные квитанции, билет продолжает действовать;
 *  - `penalty` — возврат покупателю УДЕРЖАНИЯ. На станции такого возврата
 *    нет вовсе: ведомость уже посчитала удержание, а мы лишь возвращаем
 *    деньги (например, когда возврат задержался по нашей вине).
 */
class Refund extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'money_refunded_at' => 'datetime',
        'legacy' => 'array',
        'baggage_count' => 'int',
        /*
         * Состояние на станции — отдельно от денег: возврат, сделанный при
         * недоступной станции, деньги покупателю отдаёт сразу, а
         * аннулирование бланка получает позже (досылка из АИС).
         */
        'station_annulled_at' => 'datetime',
        'station_annul_pending' => 'bool',
        'station_annul_attempted_at' => 'datetime',
        'station_backdated_at' => 'datetime',
        // Полный ответ шлюза на отказ в возврате: по короткому тексту
        // («Системная ошибка.») причину не найти.
        'bank_response' => 'array',
    ];

    /** Возврат билета: место освобождается, багаж едет вместе с ним. */
    public const KIND_TICKET = 'ticket';

    /** Возврат только багажных мест, билет остаётся действующим. */
    public const KIND_BAGGAGE = 'baggage';

    /** Возврат удержания — только деньги, станция о нём не знает. */
    public const KIND_PENALTY = 'penalty';

    public const STATUS_REFUNDED = 'refunded';           // билет аннулирован на станции

    public const STATUS_MONEY_REFUNDED = 'money_refunded'; // платёж возвращён банком

    public const STATUS_BANK_FAILED = 'bank_failed';     // вернёт бухгалтерия вручную

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Возврат, по которому взяли удержание (у kind = penalty). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_refund_id');
    }

    /**
     * Сколько удержано с покупателя этим возвратом.
     *
     * У самого возврата удержания удержания нет: он ничего не удерживает,
     * а наоборот отдаёт уже удержанное — иначе в списке получалась бы
     * отрицательная величина.
     */
    public function withheld(): float
    {
        if ($this->kind === self::KIND_PENALTY) {
            return 0.0;
        }

        /*
         * Возврат без денег — это отметка, а не возврат.
         *
         * Такие строки пишет `refunds:annul-baggage`: бланк на станции
         * аннулирован, а деньги за багаж покупателю ушли раньше, в составе
         * билета (`legacy.annulled_later`, сумма 0). По формуле ниже они
         * выглядели как возврат со 100% удержанием, и карточка предлагала
         * «вернуть удержание», которого никто не удерживал — шлюз отвечал
         * «Сумма возврата превышает сумму списания» (ловилось 16.11.2026).
         */
        if ((float) $this->amount <= 0) {
            return 0.0;
        }

        return round((float) $this->ticket_price + (float) $this->baggage_price - (float) $this->amount, 2);
    }

    /** Человеческое название вида — для списков и писем. */
    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_BAGGAGE => 'багаж',
            self::KIND_PENALTY => 'возврат удержания',
            default => 'билет',
        };
    }
}
