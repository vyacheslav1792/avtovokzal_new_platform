<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Форма обратной связи, защита от флуда: сколько обращений к форме
         * вообще допускаем с одного IP за час, включая отклонённые.
         * Лимит на ПРИНЯТЫЕ сообщения строже и живёт в FeedbackController —
         * иначе посетитель, у которого форма несколько раз не прошла
         * валидацию, остался бы без связи на час.
         */
        RateLimiter::for('feedback', fn (Request $request) => Limit::perHour(
            (int) config('feedback.flood_limit_per_hour'),
        )->by($request->ip())->response(fn () => response()->json([
            'success' => false,
            'message' => 'Вы отправили слишком много сообщений подряд. '
                .'Попробуйте позже или позвоните в справочную: 8 (4862) 72-11-11.',
        ], 429)));

        /*
         * Токены кабинета живут «полгода бездействия», а не полгода от
         * выдачи: `expiration` Sanctum считается от created_at, поэтому срок
         * проверяем сами по last_used_at (Sanctum обновляет его на каждом
         * запросе). Тот же срок — у httpOnly-cookie фронта, она продлевается
         * при каждом чтении сессии. Токены мобильного приложения пойдут
         * этим же путём.
         */
        Sanctum::authenticateAccessTokensUsing(
            function (PersonalAccessToken $token, bool $isValid) {
                if (! $isValid) {
                    return false;
                }

                $lastUsed = $token->last_used_at ?? $token->created_at;

                return $lastUsed !== null
                    && $lastUsed->gt(now()->subMinutes((int) config('site.token_idle_minutes')));
            },
        );
    }
}
