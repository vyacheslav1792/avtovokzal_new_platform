<?php

namespace App\Jobs;

use App\Models\Refund;
use App\Services\KkmApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Фискальный чек возврата (возврат прихода) через KKM API.
 *
 * Ставится в очередь `receipts` после успешного возврата платежа банком.
 * Ключ идемпотентности refund:{uuid} — повторный запуск не пробьёт
 * второй чек возврата.
 */
class RegisterRefundReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public readonly string $refundId) {}

    public function handle(KkmApiClient $kkm): void
    {
        $refund = Refund::with('order')->findOrFail($this->refundId);
        $order = $refund->order;

        $ticket = $order->tickets->first(
            fn (\App\Models\Ticket $t) => (int) $t->number === (int) $refund->number,
        );
        $tariff = ($ticket['type'] ?? 'П') === 'Д' ? 'дет.' : 'полн.';
        $factor = 1 - $refund->penalty_percent / 100;

        // Позиции чека возврата — цены за вычетом удержаний.
        $items = [[
            'name' => sprintf(
                'Билет %s-%s %s %s м. %s %s',
                $order->from_name,
                $order->to_name,
                $order->date_start->format('d.m.Y'),
                substr((string) $order->time_start, 0, 5),
                $refund->place,
                $tariff,
            ),
            'price' => round($refund->ticket_price * $factor, 2),
        ]];

        if ((float) $refund->baggage_price > 0) {
            $items[] = [
                'name' => sprintf(
                    'Багаж %s-%s %s %s',
                    $order->from_name,
                    $order->to_name,
                    $order->date_start->format('d.m.Y'),
                    substr((string) $order->time_start, 0, 5),
                ),
                'price' => round($refund->baggage_price * $factor, 2),
            ];
        }

        // Сумма к возврату считалась от общей стоимости с округлением вниз
        // (формула legacy) — построчные округления могут разойтись с ней на
        // копейки. ККТ требует точного равенства: правим последнюю позицию.
        $delta = round((float) $refund->amount - array_sum(array_column($items, 'price')), 2);
        if ($delta !== 0.0) {
            $items[count($items) - 1]['price'] = round($items[count($items) - 1]['price'] + $delta, 2);
        }

        $receipt = $kkm->registerReceipt(
            'refund:'.$refund->id,
            'refund',
            $order->email,
            $items,
            (float) $refund->amount,
        );

        if ($receipt['status'] === 'pending') {
            $this->release($this->backoff);

            return;
        }

        if ($receipt['status'] !== 'registered') {
            throw new \RuntimeException('Ошибка фискализации возврата: '.($receipt['error'] ?? 'unknown'));
        }

        $refund->update(['fiscal_sign' => $receipt['fiscal_sign'] ?? $receipt['check_number']]);
    }
}
