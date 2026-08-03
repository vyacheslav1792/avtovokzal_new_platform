<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Фискальный чек покупки (кассовый сервер ККМ).
 *
 * Ставится в очередь `receipts` при успешном оформлении заказа.
 * ВНИМАНИЕ: обработчик очереди пока сознательно НЕ запускается —
 * задания копятся в таблице jobs до запуска воркера.
 */
class RegisterFiscalReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public readonly string $orderId) {}

    public function handle(): void
    {
        $order = Order::findOrFail($this->orderId);

        // Позиции чека: билет / багаж / страховка по каждому пассажиру
        // (формат legacy payconfirm.php: namevz[i]/pricevz[i]).
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
                    'price' => $bagCount * (float) $ticket['price_bag'],
                ];
            }
        }

        $query = [
            'chek' => 1,
            'email' => $order->email,
            'key' => config('services.kkm.key'),
            'transaktion' => $order->sber_order_id,
        ];
        foreach ($items as $i => $item) {
            $query["namevz[{$i}]"] = $item['name'];
            $query["pricevz[{$i}]"] = $item['price'];
        }

        $response = Http::timeout(280)->get(config('services.kkm.url'), $query)->json();

        if (! empty($response['status']) && ! empty($response['fp'])) {
            $order->update(['fiscal_sign' => $response['fp']]);
        }
    }
}
