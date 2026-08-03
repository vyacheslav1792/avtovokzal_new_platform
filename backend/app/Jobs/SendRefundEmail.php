<?php

namespace App\Jobs;

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
 * Ставится в очередь `emails`. Обработчик очереди пока сознательно
 * НЕ запускается — SMTP подключим на этапе писем.
 */
class SendRefundEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 120;

    public function __construct(public readonly string $refundId) {}

    public function handle(): void
    {
        $refund = Refund::with('order')->findOrFail($this->refundId);
        $order = $refund->order;

        Mail::raw(
            "Билет {$order->from_name}-{$order->to_name} отправление "
            .$order->date_start->format('d.m.Y').' в '.substr((string) $order->time_start, 0, 5)
            ." место {$refund->place} аннулирован, денежные средства возвращены "
            .'с учётом комиссии автовокзала. Возвращено: '
            .number_format((float) $refund->amount, 2, '.', '').' руб.'."\r\n"
            .'Внимание! Банк перечисляет деньги с задержкой до 7 дней, в некоторых случаях до 30.',
            fn ($message) => $message->to($order->email)
                ->subject('Возврат билета на сайте Орловского автовокзала.'),
        );

        Log::info('Отправлено письмо о возврате', ['refund_id' => $refund->id]);
    }
}
