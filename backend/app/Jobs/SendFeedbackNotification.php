<?php

namespace App\Jobs;

use App\Mail\FeedbackNotificationMail;
use App\Models\FeedbackMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Уведомление службы поддержки о новом обращении с сайта. Ставится в очередь
 * `emails` — как письма о покупке; само обращение уже сохранено, поэтому
 * недоставленное письмо ничего не теряет.
 */
class SendFeedbackNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        $message = FeedbackMessage::find($this->messageId);

        if (! $message) {
            return;
        }

        // Оператор мог пометить обращение спамом, пока письмо ждало очереди.
        if ($message->isSpam()) {
            return;
        }

        Mail::to(config('feedback.notify_email'))->send(new FeedbackNotificationMail($message));

        Log::info('Отправлено уведомление об обращении с сайта', ['id' => $message->id]);
    }
}
