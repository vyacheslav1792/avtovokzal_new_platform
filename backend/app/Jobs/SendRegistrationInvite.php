<?php

namespace App\Jobs;

use App\Services\AuthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Приглашение завести личный кабинет — после первой покупки на e-mail,
 * за которым ещё нет пользователя. Ставится в очередь `emails` вместе
 * с письмом о покупке.
 */
class SendRegistrationInvite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public readonly string $email) {}

    public function handle(AuthService $auth): void
    {
        // Пользователь мог зарегистрироваться, пока письмо ждало очереди —
        // sendInvite() это проверяет и молча выходит.
        $auth->sendInvite($this->email);

        Log::info('Отправлено приглашение в личный кабинет', ['email' => $this->email]);
    }
}
