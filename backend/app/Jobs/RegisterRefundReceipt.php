<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
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

        $items = $this->items($refund, $order);

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

    /**
     * Позиции чека возврата — ровно то, за что отдаются деньги.
     *
     * Было три беды: позиция «Билет» ставилась ВСЕГДА, поэтому в чеке
     * возврата одного багажа печаталась лишняя строка «Билет … 1 x 0.00», а
     * в чеке возврата удержания — та же строка «Билет» вместо понятного
     * «Удерж.». Нулевых позиций в чеке быть не должно, а вид возврата виден
     * покупателю только из названия позиции.
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(Refund $refund, Order $order): array
    {
        $trip = sprintf(
            '%s-%s %s %s',
            $order->from_name,
            $order->to_name,
            $order->date_start->format('d.m.Y'),
            $order->displayTime(),
        );

        // Возврат удержания: денег за билет и багаж тут нет — отдаётся
        // удержанное с прошлого возврата.
        if ($refund->kind === Refund::KIND_PENALTY) {
            return [[
                'name' => sprintf('Удерж. %s м. %s', $trip, $refund->place),
                'price' => round((float) $refund->amount, 2),
            ]];
        }

        $ticket = $order->tickets->first(
            fn (Ticket $t) => (int) $t->number === (int) $refund->number,
        );
        $tariff = ($ticket['type'] ?? 'П') === 'Д' ? 'дет.' : 'полн.';
        $factor = 1 - $refund->penalty_percent / 100;

        $items = [];

        $ticketPrice = round((float) $refund->ticket_price * $factor, 2);

        if ($ticketPrice > 0) {
            $items[] = [
                'name' => sprintf('Билет %s м. %s %s', $trip, $refund->place, $tariff),
                'price' => $ticketPrice,
            ];
        }

        $baggagePrice = round((float) $refund->baggage_price * $factor, 2);

        if ($baggagePrice > 0) {
            $items[] = [
                'name' => 'Багаж '.$trip,
                'price' => $baggagePrice,
            ];
        }

        // На всякий случай: чек без позиций ККТ не примет. Сюда можно попасть
        // только при нулевых ценах в самом возврате.
        if ($items === []) {
            $items[] = [
                'name' => sprintf('Возврат %s м. %s', $trip, $refund->place),
                'price' => round((float) $refund->amount, 2),
            ];
        }

        return $items;
    }
}
