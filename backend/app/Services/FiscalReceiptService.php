<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Фискальный чек покупки: сборка позиций и отправка в KKM API.
 *
 * Чек пробивается **в два захода**:
 *  1. сразу при оформлении, синхронно и с коротким ожиданием (5 секунд) —
 *     чтобы фискальный признак попал в билет, который покупатель открывает
 *     в ту же секунду;
 *  2. не успели (ККТ занята, смена закрывается, сервис недоступен) — чек
 *     уходит в очередь `receipts` и пробивается там.
 *
 * Ключ идемпотентности `sale:{id}` общий для обоих заходов, поэтому второй
 * чек не пробьётся никогда: KKM API вернёт уже зарегистрированный.
 */
class FiscalReceiptService
{
    /** Сколько ждать ККТ при оформлении: дольше покупателя держать нельзя. */
    public const SYNC_TIMEOUT = 5;

    public function __construct(private readonly KkmApiClient $kkm) {}

    /**
     * Пробить чек сейчас. Возвращает фискальный признак или null, если
     * не получилось — тогда чек должен уйти в очередь.
     *
     * Исключения наружу не выпускает: неудача фискализации не должна
     * рушить оформление уже оплаченного заказа.
     */
    public function registerNow(Order $order): ?string
    {
        try {
            $receipt = $this->send($order, self::SYNC_TIMEOUT);
        } catch (Throwable $e) {
            Log::warning('Чек сразу пробить не удалось, уйдёт в очередь', [
                'order' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (($receipt['status'] ?? null) !== 'registered') {
            return null;
        }

        $sign = $receipt['fiscal_sign'] ?? $receipt['check_number'] ?? null;

        if ($sign) {
            $order->update(['fiscal_sign' => $sign]);
        }

        return $sign;
    }

    /**
     * Отправка чека в KKM API. `$timeout` null — обычный таймаут сервиса
     * (так ходит очередь: там ждать можно).
     *
     * @return array data чека: status, check_number, fiscal_sign, …
     */
    public function send(Order $order, ?int $timeout = null): array
    {
        return $this->kkm->registerReceipt(
            'sale:'.$order->id,
            'sale',
            $order->email,
            $this->items($order),
            (float) $order->total_price,
            $timeout,
        );
    }

    /**
     * Позиции чека: билет и багаж по каждому пассажиру. Суммы обязаны
     * сходиться с оплатой до копейки — иначе ККТ чек не примет.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(Order $order): array
    {
        $items = [];

        foreach ($order->tickets ?? [] as $ticket) {
            $tariff = $ticket['type'] === 'Д' ? 'дет.' : 'полн.';

            $items[] = [
                'name' => sprintf(
                    'Билет %s-%s %s %s м. %s %s',
                    $order->from_name,
                    $order->to_name,
                    $order->date_start->format('d.m.Y'),
                    $order->displayTime(),
                    $ticket['place'],
                    $tariff,
                ),
                'price' => (float) $ticket['price'],
            ];

            $bagCount = (int) ($ticket['baggage'] ?? 0);

            if ($bagCount > 0 && (float) ($ticket['price_bag'] ?? 0) > 0) {
                $items[] = [
                    'name' => sprintf(
                        'Багаж %s-%s %s %s',
                        $order->from_name,
                        $order->to_name,
                        $order->date_start->format('d.m.Y'),
                        $order->displayTime(),
                    ),
                    'price' => (float) $ticket['price_bag'],
                    'quantity' => $bagCount,
                ];
            }
        }

        return $items;
    }
}
