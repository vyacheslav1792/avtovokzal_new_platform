<?php

namespace App\Console\Commands;

use App\Models\EmployeeToken;
use Illuminate\Console\Command;

/**
 * Убирает мёртвые сессии сотрудников. Истёкшая сессия и так не пускает
 * (проверка при каждом запросе), но строки копятся — по одной на каждый
 * вход с каждой машины.
 */
class PruneEmployeeTokensCommand extends Command
{
    protected $signature = 'ais:prune-tokens';

    protected $description = 'Удалить истёкшие сессии сотрудников АИС';

    public function handle(): int
    {
        $idle = (int) config('ais_auth.session_idle_minutes');
        $max = (int) config('ais_auth.session_max_minutes');
        $deleted = 0;

        if ($idle > 0) {
            // last_used_at ставится при выдаче, но у старых строк мог
            // остаться null — тогда считаем по дате создания.
            $deleted += EmployeeToken::query()
                ->where(fn ($q) => $q
                    ->where('last_used_at', '<', now()->subMinutes($idle))
                    ->orWhere(fn ($q) => $q->whereNull('last_used_at')
                        ->where('created_at', '<', now()->subMinutes($idle))))
                ->delete();
        }

        if ($max > 0) {
            $deleted += EmployeeToken::where('created_at', '<', now()->subMinutes($max))->delete();
        }

        $this->info("Удалено сессий: $deleted");

        return self::SUCCESS;
    }
}
