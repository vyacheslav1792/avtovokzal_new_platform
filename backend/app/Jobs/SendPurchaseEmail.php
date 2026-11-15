<?php

namespace App\Jobs;

use App\Mail\PurchaseMail;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Письмо покупателю с билетами после успешного оформления.
 *
 * Ставится в очередь `emails`. Билеты прикладываются одним PDF — его рендерит
 * Gotenberg по той же печатной форме `/print/{id}`, что отдаёт сайт; legacy
 * присылал только ссылку на `print.php?bilet=…`.
 */
class SendPurchaseEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 120;

    public function __construct(public readonly string $orderId) {}

    public function handle(PdfRenderer $renderer): void
    {
        $order = Order::with('tickets')->findOrFail($this->orderId);

        // Почты у покупателя нет (вход через VK без e-mail): в заказе стоит
        // служебный адрес для фискального чека, и наши письма туда не идут.
        if (OrderService::isFiscalFallbackEmail($order->email)) {
            Log::info('Письмо о покупке не отправляем: у покупателя нет почты', [
                'order_id' => $order->id,
            ]);

            return;
        }

        // Вложение необязательно: список билетов в письме есть всегда, и
        // недоступный Gotenberg не повод оставить покупателя без письма.
        $pdf = null;

        // Печатная форма живёт только у оформленного заказа. Без этой
        // проверки рендерилась страница «Информация по заказу не найдена»
        // и уходила покупателю под именем «Билеты …pdf».
        if ($order->status !== Order::STATUS_COMPLETED || $order->tickets->isEmpty()) {
            Log::warning('Письмо о покупке уходит без PDF: заказ не оформлен', [
                'order_id' => $order->id,
                'status' => $order->status,
            ]);

            Mail::to($order->email)->send(new PurchaseMail($order, null));

            return;
        }

        try {
            $pdf = $renderer->fromUrl(
                rtrim((string) config('services.gotenberg.print_base'), '/').'/print/'.$order->id,
            );
        } catch (\Throwable $e) {
            Log::warning('Письмо о покупке уходит без PDF билетов', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        Mail::to($order->email)->send(new PurchaseMail($order, $pdf));

        Log::info('Отправлено письмо о покупке', [
            'order_id' => $order->id,
            'pdf' => $pdf !== null,
        ]);
    }
}
