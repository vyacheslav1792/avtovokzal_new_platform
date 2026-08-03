<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\KkmApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Фискальный чек покупки через KKM API (сервис-прослойка к KkmServer).
 *
 * Ставится в очередь `receipts` при успешном оформлении заказа.
 * Ключ идемпотентности sale:{uuid} — повторный запуск джобы (retry,
 * дубль в очереди) не пробьёт второй чек.
 */
class RegisterFiscalReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public readonly string $orderId) {}

    public function handle(KkmApiClient $kkm): void
    {
        $order = Order::findOrFail($this->orderId);

        // Позиции чека: билет / багаж по каждому пассажиру.
        $items = [];
        foreach ($order->tickets ?? [] as $ticket) {
            $tariff = $ticket['type'] === 'Д' ? 'дет.' : 'полн.';
            $items[] = [
                'name' => sprintf(
                    'Билет %s-%s %s %s м. %s %s',
                    $order->from_name,
                    $order->to_name,
                    $order->date_start->format('d.m.Y'),
                    substr((string) $order->time_start, 0, 5),
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
                        substr((string) $order->time_start, 0, 5),
                    ),
                    'price' => (float) $ticket['price_bag'],
                    'quantity' => $bagCount,
                ];
            }
        }

        $receipt = $kkm->registerReceipt(
            'sale:'.$order->id,
            'sale',
            $order->email,
            $items,
            (float) $order->total_price,
        );

        if ($receipt['status'] === 'pending') {
            $this->release($this->backoff); // ККТ ещё пробивает — спросим позже

            return;
        }

        if ($receipt['status'] !== 'registered') {
            throw new \RuntimeException('Ошибка фискализации: '.($receipt['error'] ?? 'unknown'));
        }

        $order->update(['fiscal_sign' => $receipt['fiscal_sign'] ?? $receipt['check_number']]);
    }
}
