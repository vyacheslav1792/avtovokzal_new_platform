<?php

namespace App\Jobs;

use App\Mail\RefundMail;
use App\Services\OrderService;
use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Письмо покупателю о возврате билета (текст legacy backbycode.php).
 *
 * Ставится в очередь `emails`.
 */
class SendRefundEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 120;

    public function __construct(public readonly string $refundId) {}

    public function handle(): void
    {
        // tickets нужны шаблону: по ним он решает, упоминать ли остальные
        // билеты заказа.
        $refund = Refund::with('order.tickets')->findOrFail($this->refundId);

        // У покупателя может не быть почты (вход через VK): в заказе стоит
        // служебный адрес для фискального чека, наши письма туда не идут.
        if (OrderService::isFiscalFallbackEmail($refund->order->email)) {
            Log::info('Письмо о возврате не отправляем: у покупателя нет почты', [
                'refund_id' => $refund->id,
            ]);

            return;
        }

        Mail::to($refund->order->email)->send(new RefundMail($refund));

        Log::info('Отправлено письмо о возврате', ['refund_id' => $refund->id]);
    }
}
