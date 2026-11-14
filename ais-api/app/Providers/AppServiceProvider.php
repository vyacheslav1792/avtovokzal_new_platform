<?php

namespace App\Providers;

use App\Services\ModuleRegistry;
use App\Support\PasswordStrengthEstimator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Каталог модулей собирается из meta() всех отчётов — за запрос
        // его спрашивают и проверка прав, и редактор ролей.
        $this->app->singleton(ModuleRegistry::class);

        // zxcvbn разворачивает свои словари при создании; на проверке
        // нескольких раскладок одного пароля это заметно.
        $this->app->singleton(PasswordStrengthEstimator::class);
    }

    public function boot(): void
    {
        //
    }
}
