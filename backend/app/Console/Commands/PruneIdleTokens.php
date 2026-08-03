<?php

namespace App\Console\Commands;

use App\Models\AuthToken;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Чистка токенов кабинета, которыми давно не пользовались.
 *
 * Проверку «полгода бездействия» делает AppServiceProvider на каждом
 * запросе — команда лишь убирает из таблицы мёртвые строки, чтобы
 * personal_access_tokens не рос бесконечно. Заодно удаляет протухшие
 * ссылки писем (auth_tokens).
 */
class PruneIdleTokens extends Command
{
    protected $signature = 'auth:prune-tokens {--days= : Срок бездействия в днях (по умолчанию — из config/site.php)}';

    protected $description = 'Удалить токены кабинета без активности и просроченные ссылки из писем';

    public function handle(): int
    {
        $minutes = $this->option('days') !== null
            ? (int) $this->option('days') * 24 * 60
            : (int) config('site.token_idle_minutes');

        $threshold = now()->subMinutes($minutes);

        $tokens = PersonalAccessToken::query()
            ->where(function ($q) use ($threshold) {
                $q->where('last_used_at', '<', $threshold)
                    ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $threshold));
            })
            ->delete();

        // Ссылки из писем: просроченные больше месяца назад уже не нужны
        // ни для проверки, ни для разбора инцидентов.
        $links = AuthToken::where('expires_at', '<', now()->subMonth())->delete();

        $this->info("Удалено токенов: {$tokens}, устаревших ссылок из писем: {$links}.");

        return self::SUCCESS;
    }
}
