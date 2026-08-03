<?php

namespace App\Providers;

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
