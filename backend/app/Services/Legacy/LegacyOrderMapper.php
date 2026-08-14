<?php

namespace App\Services\Legacy;

use Illuminate\Support\Str;

/**
 * Перекладка строки legacy site.ORDERS/BACKS в новую схему (orders, tickets,
 * refunds). Вынесено из команды отдельным классом: логика маппинга — то, что
 * придётся перечитывать и править, а не то, как крутится цикл.
 *
 * Что важно знать про legacy-данные:
 *  - BUYERS/BILETS — JSON-массивы пассажиров; BILETS = BUYERS + serial/number
 *    (+ ключ BAGAJ с багажными квитанциями). До 2019 г. в них не было ни
 *    кода гражданства (было grj — название строкой), ни багажа со страховкой.
 *  - ORDER_ID — 30 hex-символов, переносим как есть (orders.id — varchar(36)).
 *  - Числовой STATUS legacy (см. statusMap) в новую схему не ложится один в
 *    один: 5 и 6 — это оформленные заказы, по которым позже был возврат, а
 *    сам возврат живёт в BACKS/refunds.
 *  - Всё, чему нет места в новой схеме, кладётся в jsonb legacy — ничего не
 *    выбрасываем.
 */
class LegacyOrderMapper
{
    /** legacy STATUS → статус нового заказа. */
    private const STATUS_MAP = [
        -4 => 'pending',              // заказ создан, оплата ещё не начата
        4 => 'payment_registered',    // платёж зарегистрирован в Сбербанке
        40 => 'paid',                 // оплата подтверждена, идёт оформление
        1 => 'completed',             // билеты оформлены
        5 => 'completed',             // оформлен, позже возвращён целиком
        6 => 'completed',             // оформлен, позже возвращён с удержанием
        2 => 'failed',                // оформить не удалось
        3 => 'cancelled',             // платёж отменён после неудачи оформления
        -1 => 'failed',               // оплачен, но не оформлен — помечен на отмену
        -2 => 'cancelled',            // платёж отменён (reverse)
        -3 => 'failed',               // платёж отклонён банком
    ];

    /** @return array{order: array, tickets: array<int, array>} */
    public function order(array $row): array
    {
        $status = (int) $row['STATUS'];
        $buyers = $this->json(LegacySiteConnection::blob($row['BUYERS'] ?? null));
        $tickets = $this->json(LegacySiteConnection::blob($row['BILETS'] ?? null));

        $createdAt = $this->dateTime($row['DATE_ORDER'] ?? null, $row['TIME_ORDER'] ?? null);
        $orderId = trim((string) $row['ORDER_ID']);

        $order = [
            'id' => $orderId,
            'awtcod' => (int) $row['FROM_ID'],
            'napcod' => (int) $row['NAPCOD'],
            'raicecod' => (int) $row['RAICECOD'],
            'statcod' => (int) $row['STATCODE'],
            'from_name' => $this->cut($row['FROM2'] ?? '', 128),
            'to_name' => $this->cut($row['TO2'] ?? '', 128),
            'trip_name' => $this->cut($row['RAICENAME'] ?? null, 128) ?: null,
            'carrier' => $this->cut(LegacySiteConnection::blob($row['ATP'] ?? null), 128) ?: null,
            'date_start' => $this->date($row['DATESTART'] ?? null),
            'time_start' => $this->time($row['TIMESTART'] ?? null),
            'arrival_at' => $this->dateTime($row['TIME_END'] ?? null),
            'reservation_id' => null,
            'id_time_places' => $this->cut($row['ZAKAZ'] ?? null, 32) ?: null,
            'passengers' => $this->jsonColumn($this->passengers($buyers ?: $tickets)),
            'total_price' => (float) ($row['PRICE'] ?? 0),
            'user_id' => null,
            'email' => $this->cut($row['EMAIL'] ?? '', 255),
            'phone' => $this->cut($row['PHONE'] ?? null, 32) ?: null,
            'ip' => $this->cut($row['IP'] ?? null, 45) ?: null,
            'sber_order_id' => $this->cut($row['SBERBANK'] ?? null, 64) ?: null,
            'payment_url' => null,
            'fiscal_sign' => $this->cut($row['FP'] ?? null, 64) ?: null,
            'back_code' => $this->cut($row['BACK_CODE'] ?? null, 32) ?: null,
            'status' => self::STATUS_MAP[$status] ?? 'pending',
            'status_reason' => null,
            'paid_at' => $this->dateTime($row['DATE_PAY'] ?? null, $row['TIME_PAY'] ?? null),
            'completed_at' => $this->dateTime($row['DATE_BUY'] ?? null),
            'legacy' => $this->jsonColumn($this->orderLegacy($row, $status)),
            'created_at' => $createdAt,
            'updated_at' => $this->fromUnix($row['UPDATE2'] ?? null) ?? $createdAt,
        ];

        return ['order' => $order, 'tickets' => $this->tickets($orderId, $tickets, $buyers, $createdAt)];
    }

    /** Строка BACKS → строка refunds. */
    public function refund(array $row, string $orderId): array
    {
        $ticketPrice = (float) ($row['BIL'] ?? 0);
        $baggagePrice = (float) ($row['BAGAJ'] ?? 0);
        $insurance = (float) ($row['STRAH'] ?? 0);
        $amount = (float) ($row['SUMM_BACK'] ?? 0);
        $paid = $ticketPrice + $baggagePrice + $insurance;

        // PERSENT в legacy заполнялся не всегда — тогда считаем удержание из сумм.
        $percent = $row['PERSENT'] !== null
            ? (int) round((float) $row['PERSENT'])
            : ($paid > 0 ? (int) round((1 - $amount / $paid) * 100) : 0);

        $moneyBackAt = $this->dateTime($row['TIME_MONEY_BACK'] ?? null)
            ?? $this->dateTime($row['DATE_MONEY_BACK'] ?? null);
        $createdAt = $this->dateTime($row['TIME_BACK'] ?? null)
            ?? $this->dateTime($row['DATE_BACK'] ?? null);

        return [
            'id' => (string) Str::uuid7(),
            'order_id' => $orderId,
            'serial' => $this->cut($row['SERIAL'] ?? '', 20),
            'number' => $this->cut($row['NUMBER'] ?? '', 20),
            'place' => $row['PLACE2'] !== null ? (int) $row['PLACE2'] : null,
            'penalty_percent' => max(0, min(100, $percent)),
            'ticket_price' => $ticketPrice,
            'baggage_price' => $baggagePrice,
            'amount' => $amount,
            'status' => $moneyBackAt ? 'money_refunded' : 'refunded',
            'bank_error' => null,
            'money_refunded_at' => $moneyBackAt,
            'fiscal_sign' => $this->cut($row['FP'] ?? null, 64) ?: null,
            'legacy' => $this->jsonColumn($this->filled([
                'who_back' => $row['WHO_BACK'] ?? null,
                'type_back' => $row['TYPE_BACK'] ?? null,
                'persent' => $row['PERSENT'] ?? null,
                'strah' => $row['STRAH'] ?? null,
                'strah_back' => $row['STRAH_BACK'] ?? null,
                'bil_back' => $row['BIL_BACK'] ?? null,
                'bagaj_back' => $row['BAGAJ_BACK'] ?? null,
                'date_order2' => $this->date($row['DATE_ORDER2'] ?? null),
                'datestart2' => $this->date($row['DATESTART2'] ?? null),
                'update2' => $row['UPDATE2'] ?? null,
            ])),
            'created_at' => $createdAt,
            'updated_at' => $moneyBackAt ?? $createdAt,
        ];
    }

    /** Пассажиры заказа в формате нового сайта (orders.passengers). */
    private function passengers(array $rows): array
    {
        $out = [];
        foreach ($rows as $p) {
            if (! is_array($p)) {
                continue;
            }

            $out[] = [
                'place' => trim((string) ($p['place'] ?? '')),
                'type' => $this->type($p),
                'price' => (float) ($p['price'] ?? 0),
                'last_name' => $this->cut($p['f'] ?? '', 40),
                'first_name' => $this->cut($p['i'] ?? '', 30),
                'middle_name' => $this->cut($p['o'] ?? '', 30),
                'birth_date' => $this->date($p['dr'] ?? null),
                'gender' => $this->gender($p['pol'] ?? null),
                'doc_type' => (int) ($p['doc'] ?? 21),
                'doc_series' => $this->cut($p['doc_s'] ?? '', 10),
                'doc_number' => $this->cut($p['doc_num'] ?? '', 25),
                'citizenship' => (int) ($p['grajd'] ?? 185),
                'baggage' => (int) ($p['bag'] ?? 0),
                'price_bag' => (float) ($p['price_bag'] ?? 0),
                'comment' => $this->cut($p['comments'] ?? '', 40),
            ];
        }

        return $out;
    }

    /**
     * Билеты заказа. Пол в BILETS есть не всегда — берём из BUYERS по месту,
     * как это делает оформление на новом сайте.
     *
     * @return array<int, array>
     */
    private function tickets(string $orderId, array $rows, array $buyers, ?string $createdAt): array
    {
        $genders = [];
        foreach ($buyers as $b) {
            if (is_array($b)) {
                $genders[trim((string) ($b['place'] ?? ''))] = $this->gender($b['pol'] ?? null);
            }
        }

        $out = [];
        foreach (array_values($rows) as $i => $t) {
            if (! is_array($t)) {
                continue;
            }

            $place = trim((string) ($t['place'] ?? ''));

            $out[] = [
                'id' => (string) Str::uuid7(),
                'order_id' => $orderId,
                'position' => $i,
                'serial' => $this->cut($t['serial'] ?? null, 20) ?: null,
                'number' => $this->cut($t['number'] ?? null, 20) ?: null,
                'place' => (int) $place,
                'type' => $this->type($t),
                'price' => (float) ($t['price'] ?? 0),
                'baggage' => (int) ($t['bag'] ?? 0),
                'price_bag' => (float) ($t['price_bag'] ?? 0),
                'last_name' => $this->cut($t['f'] ?? '', 40),
                'first_name' => $this->cut($t['i'] ?? '', 30),
                'middle_name' => $this->cut($t['o'] ?? '', 30) ?: null,
                'birth_date' => $this->date($t['dr'] ?? null),
                'gender' => $this->gender($t['pol'] ?? null) ?? ($genders[$place] ?? null),
                'doc_type' => (int) ($t['doc'] ?? 21),
                'doc_series' => $this->cut($t['doc_s'] ?? null, 10) ?: null,
                'doc_number' => $this->cut($t['doc_num'] ?? '', 25),
                'citizenship' => (int) ($t['grajd'] ?? 185),
                'comment' => $this->cut($t['comments'] ?? null, 40) ?: null,
                'baggage_tickets' => $this->jsonColumn(is_array($t['BAGAJ'] ?? null) ? $t['BAGAJ'] : []),
                'legacy' => $this->jsonColumn($this->filled([
                    'grj' => $t['grj'] ?? null,           // гражданство строкой (до кодов)
                    'doc_orig' => $t['doc_orig'] ?? null,
                    'dobrstr' => $t['dobrstr'] ?? null,   // добровольное страхование
                    'price_dobrstr' => $t['price_dobrstr'] ?? null,
                ])),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        return $out;
    }

    /** Поля legacy-заказа, которым нет места в новой схеме. */
    private function orderLegacy(array $row, int $status): array
    {
        return $this->filled([
            'status' => $status,
            'buy_end' => $row['BUY_END'] ?? null,
            'platnom' => $row['PLATNOM'] ?? null,
            'napname' => $this->cut($row['NAPNAME'] ?? null, 255) ?: null,
            'fraht' => $row['FRAHT'] ?? null,
            'prices' => $row['PRICES'] === null ? null : $this->clean((string) LegacySiteConnection::blob($row['PRICES'])),
            'strah' => $row['STRAH'] ?? null,
            'bagaj' => $row['BAGAJ'] ?? null,
            'bil' => $row['BIL'] ?? null,
            'summ_back' => $row['SUMM_BACK'] ?? null,
            'fp_back' => $row['FP_BACK'] === null ? null : trim((string) $row['FP_BACK']),
            'crone' => $row['CRONE'] ?? null,
            'time_live' => $row['TIME_LIVE'] ?? null,
            'comment' => $row['COMMENT'] === null ? null : $this->clean((string) LegacySiteConnection::blob($row['COMMENT'])),
            'back_bilets' => $row['BACK_BILETS'] === null ? null : $this->clean((string) LegacySiteConnection::blob($row['BACK_BILETS'])),
        ]);
    }

    /** Полный/детский: legacy пишет 'П'/'Д', но встречается и пустое поле. */
    private function type(array $row): string
    {
        $type = trim((string) ($row['type'] ?? ''));

        return $type === '' ? 'П' : mb_substr($type, 0, 1);
    }

    private function gender(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strtoupper(mb_substr($value, 0, 1));
    }

    private function json(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cut(?string $value, int $length): string
    {
        return mb_substr(trim($this->clean((string) $value)), 0, $length);
    }

    /**
     * Postgres не принимает нулевой байт ни в varchar, ни в jsonb (там его не
     * спасает даже escape-последовательность), а в legacy-блобах он попадается.
     * Заодно выкидываем прочие управляющие символы, кроме перевода строки.
     */
    private function clean(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    }

    /** Значение jsonb-колонки: без нулевых символов и с заменой битого UTF-8. */
    private function jsonColumn(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        // json_encode превращает нулевой байт в escape \u0000, а jsonb
        // Postgres такую последовательность отвергает.
        return str_replace('\u0000', '', $json === false ? '[]' : $json);
    }

    /** Только непустые значения — не раздуваем jsonb нулями и null. */
    private function filled(array $values): array
    {
        return array_filter($values, fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 0.0);
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = substr($value, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date !== '0000-00-00' ? $date : null;
    }

    private function time(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Firebird отдаёт TIME как '12:15:00.0000', а иногда как часть timestamp.
        if (preg_match('/(\d{2}:\d{2}:\d{2})/', $value, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** DATE + TIME (или готовый TIMESTAMP) → 'Y-m-d H:i:s' московского времени. */
    private function dateTime(?string $date, ?string $time = null): ?string
    {
        $day = $this->date($date);
        if ($day === null) {
            return null;
        }

        $clock = $this->time($time ?? $date) ?? '00:00:00';

        return $day.' '.$clock;
    }

    private function fromUnix(mixed $value): ?string
    {
        $ts = (int) $value;

        return $ts > 0 ? date('Y-m-d H:i:s', $ts) : null;
    }
}
