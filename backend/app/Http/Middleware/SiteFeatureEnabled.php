<?php

namespace App\Http\Middleware;

use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Выключатели сайта из АИС (раздел «Настройки»): продажа и возврат билетов.
 *
 * Проверка стоит ИМЕННО на сервере: фронт по тем же настройкам убирает
 * формы, но заказ можно отправить и в обход страницы. В legacy такого
 * выключателя не было — при неполадках у станции покупатель оплачивал
 * билет, который потом не оформлялся.
 *
 * Чего выключатель НЕ касается (и не должен):
 *   — дооформления уже оплаченного заказа (orders/confirm): деньги у нас,
 *     билеты обязаны появиться;
 *   — возврата через АИС (admin/refunds/*): это инструмент кассира.
 */
class SiteFeatureEnabled
{
    public function __construct(private readonly SettingService $settings) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $enabled = match ($feature) {
            'sales' => $this->settings->salesEnabled(),
            'refunds' => $this->settings->refundsEnabled(),
            default => true,
        };

        if ($enabled) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'feature' => $feature,
            'error' => config("site.{$feature}_disabled_text"),
        ], 503);
    }
}
