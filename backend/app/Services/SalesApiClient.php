<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Клиент API продаж (отдельный сервис sales-api).
 * Высокоуровневое API не ходит в базы автостанций напрямую — только сюда.
 */
class SalesApiClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.sales_api.url'))
            ->withToken(config('services.sales_api.token'))
            ->timeout(config('services.sales_api.timeout'))
            ->acceptJson();
    }

    public function stations(): array
    {
        return $this->call(fn () => $this->http()->get('stations'));
    }

    public function destinations(int $awtcod): array
    {
        return $this->call(fn () => $this->http()->get("stations/{$awtcod}/destinations"));
    }

    /**
     * Пункты назначения ОТ ОСТАНОВКИ на трассе (продажа от пункта
     * посадки, docs/BOARDING_POINTS.md): у остановки свои тарифы, и
     * список «Куда» у неё короче станционного.
     */
    public function destinationsFromStop(int $awtcod, string $stop): array
    {
        return $this->call(fn () => $this->http()->get("stations/{$awtcod}/destinations", [
            'from' => $stop,
        ]));
    }

    /** Регулярное расписание станции (legacy action=raspisanie). */
    public function schedule(int $awtcod): array
    {
        return $this->call(fn () => $this->http()->get("stations/{$awtcod}/schedule"));
    }

    /**
     * Тариф рейса по остановкам (legacy action=tarif).
     *
     * ЛОВУШКА: отбрасываем только null. `array_filter` без колбэка
     * выбрасывает и нули, поэтому `raicecod=0` исчезал из запроса, а
     * станция отвечала «The raicecod field is required» — сообщение,
     * по которому причину не найти. Так же сделано в trips().
     */
    public function tariff(int $awtcod, int $napcod, int $raicecod, ?string $variant = null): array
    {
        return $this->call(fn () => $this->http()->get("stations/{$awtcod}/tariff", array_filter([
            'napcod' => $napcod,
            'raicecod' => $raicecod,
            'variant' => $variant,
        ], fn ($v) => $v !== null)));
    }

    /**
     * $date = null — «ближайшие» рейсы по всем датам (legacy blij=1).
     *
     * $fromStop — остановка отправления на трассе рейса: тогда и цена, и
     * время отправления считаются от неё, а рейсы без тарифа или с
     * запрещённой посадкой в список не попадают.
     */
    public function trips(
        int $awtcod,
        string $station,
        ?string $date,
        bool $forSale = true,
        string $fromStop = '',
    ): array {
        return $this->call(fn () => $this->http()->get('trips', array_filter([
            'awtcod' => $awtcod,
            'station' => $station,
            'date' => $date,
            'for_sale' => $forSale ? 1 : 0,
            'from' => $fromStop !== '' ? $fromStop : null,
        ], fn ($v) => $v !== null)));
    }

    /**
     * Места рейса. $station — пункт назначения покупателя: с ним станция
     * учитывает участок пути (место, занятое только до середины трассы,
     * для билета до неё свободно) и картина совпадает с тем, что даст
     * оформить резерв.
     */
    public function places(
        int $awtcod,
        string $date,
        int $napcod,
        int $raicecod,
        string $station = '',
    ): array {
        return $this->call(fn () => $this->http()->get('trips/places', array_filter([
            'awtcod' => $awtcod,
            'date' => $date,
            'napcod' => $napcod,
            'raicecod' => $raicecod,
            'station' => $station !== '' ? $station : null,
        ], fn ($v) => $v !== null)));
    }

    public function reserve(array $payload): array
    {
        return $this->call(fn () => $this->http()->post('reservations', $payload));
    }

    /** Снять бронь мест: покупатель отказался от оплаты. */
    public function cancelReservation(string $reservationId): array
    {
        return $this->call(fn () => $this->http()->delete("reservations/{$reservationId}"));
    }

    public function sell(array $payload): array
    {
        return $this->call(fn () => $this->http()->post('sales', $payload));
    }

    public function refund(array $payload): array
    {
        return $this->call(fn () => $this->http()->post('refunds', $payload));
    }

    /**
     * Состояние бланков в журнале станции (только чтение): продан,
     * возвращён, кем и когда.
     *
     * Нужно карточке возврата в АИС: своей таблицы возвратов мало —
     * бланк может быть аннулирован на станции БЕЗ записи у нас (отказ по
     * другому бланку отменял запись, но не аннулирование), а ещё его мог
     * вернуть кассир станции, и тогда деньги покупателю выданы там.
     *
     * @param  array<int, array{serial: string, number: string}>  $blanks
     */
    public function blanks(int $awtcod, array $blanks, ?string $dateStart = null): array
    {
        return $this->call(fn () => $this->http()->get('blanks', array_filter([
            'awtcod' => $awtcod,
            'date_start' => $dateStart,
            'blanks' => array_values($blanks),
        ], fn ($v) => $v !== null)));
    }

    /** Строка EVRYRAICE (в т.ч. флаги CANCELED/DISRUPT — отменён/сорван). */
    public function tripStatus(int $awtcod, string $date, int $napcod, int $raicecod): array
    {
        return $this->call(fn () => $this->http()->get('trips/status', [
            'awtcod' => $awtcod,
            'date' => $date,
            'napcod' => $napcod,
            'raicecod' => $raicecod,
        ]));
    }

    private function call(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            /*
             * Сервис продаж не ответил вовсе (не поднят, перезапускается,
             * сеть). Для вызывающего это ВРЕМЕННАЯ помеха: дооформление
             * оплаченного заказа не должно из-за неё портить ему статус
             * (OrderService::TEMPORARY_CODES), а покупателю нужен понятный
             * текст вместо «cURL error 7».
             */
            throw new SalesApiException(
                'Сервис продажи билетов сейчас недоступен. Попробуйте позже.',
                'sales_api_unavailable',
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            /*
             * 502/503/504 без JSON — это ответ nginx, а не API продаж:
             * сервис перезапускается (деплой), не поднялся или не успел
             * ответить. По смыслу то же, что отсутствие ответа, поэтому и
             * код тот же: покупателю понятный текст вместо «Некорректный
             * ответ API продаж», а дооформлению оплаченного заказа —
             * временная помеха, от которой статус не портится.
             */
            if (in_array($response->status(), [502, 503, 504], true)) {
                throw new SalesApiException(
                    'Сервис продажи билетов сейчас недоступен. Попробуйте позже.',
                    'sales_api_unavailable',
                    $response->status(),
                );
            }

            throw new SalesApiException(
                'Некорректный ответ API продаж',
                'bad_response',
                $response->status(),
            );
        }

        if (! ($data['success'] ?? false)) {
            /*
             * Код ответа передаём в исключение: по нему видно, отказ это
             * из-за присланных данных (4xx — тогда в журнал не пишем, боты
             * шлют что угодно) или сбой системы (5xx, нет ответа).
             * Сообщение берём и из `errors` — у ошибок проверки API продаж
             * текст лежит именно там, а `error`/`message` даёт общее
             * «The raicecod field is required», по которому причину не
             * найти.
             */
            throw new SalesApiException(
                $data['error'] ?? $this->firstValidationError($data) ?? $data['message'] ?? 'Ошибка API продаж',
                $data['error_code'] ?? 'sales_api_error',
                $response->status(),
                (array) ($data['details'] ?? []),
            );
        }

        return $data;
    }

    /** Первая ошибка проверки из ответа API продаж, если она там есть. */
    private function firstValidationError(array $data): ?string
    {
        foreach ((array) ($data['errors'] ?? []) as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return null;
    }
}
