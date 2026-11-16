<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запрет действий из сессии «вход в кабинет от имени покупателя».
 *
 * Сотрудник заходит в кабинет, чтобы УВИДЕТЬ то же, что видит покупатель:
 * какие заказы отображаются, почему не приходит код, что показывает
 * напоминание о незавершённой покупке. Тратить чужие деньги и менять чужие
 * данные при этом нельзя — покупку и возврат оформляют своими
 * инструментами (форма продажи и `/back-ticket`), где всё пишется в
 * журнал на имя сотрудника.
 *
 * Признак такой сессии — способность `impersonated` у токена Sanctum
 * (см. CustomerAdminService::consumeImpersonationCode).
 */
class DenyImpersonated
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Guard указываем ЯВНО. Публичные маршруты (покупка, отмена заказа)
         * идут без `auth:sanctum`, и `$request->user()` там берёт guard по
         * умолчанию — то есть null, даже когда Bearer-токен прислан.
         * Покупка из чужой сессии так проходила бы мимо запрета, а сам
         * контроллер покупателя берёт именно `user('sanctum')`.
         */
        $token = $request->user('sanctum')?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->can('impersonated')) {
            return response()->json([
                'success' => false,
                'error' => 'Вы вошли в кабинет от имени покупателя — в этом режиме можно только '
                    .'смотреть. Покупка, возврат и изменение данных отсюда запрещены.',
                'impersonation_readonly' => true,
            ], 403);
        }

        return $next($request);
    }
}
