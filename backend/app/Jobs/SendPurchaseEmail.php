<?php

namespace App\Jobs;

use App\Models\Order;
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
 * Ставится в очередь `emails`. Обработчик очереди пока сознательно
 * НЕ запускается — шаблон письма и SMTP подключим на следующем этапе.
 */
class SendPurchaseEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 120;

    public function __construct(public readonly string $orderId) {}

    public function handle(): void
    {
        $order = Order::findOrFail($this->orderId);

        // TODO(этап писем): вёрстка html-шаблона (legacy emails/buy_success.php),
        // ссылка на печать билетов и код возврата.
        Mail::raw(
            "Вы успешно приобрели билеты на рейс {$order->from_name} — {$order->to_name} "
            ."{$order->date_start->format('d.m.Y')} {$order->time_start}. "
            ."Код для возврата: {$order->back_code}.",
            fn ($message) => $message->to($order->email)
                ->subject('Успешная покупка билетов на сайте Орловского автовокзала.'),
        );

        Log::info('Отправлено письмо о покупке', ['order_id' => $order->id]);
    }
}
