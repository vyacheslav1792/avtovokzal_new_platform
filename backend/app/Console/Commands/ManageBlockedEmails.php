<?php

namespace App\Console\Commands;

use App\Models\BlockedEmail;
use Illuminate\Console\Command;

/**
 * Управление чёрным списком адресов (таблица blocked_emails, перенос
 * legacy $CHS). В legacy список правился в исходнике и требовал выкладки.
 */
class ManageBlockedEmails extends Command
{
    protected $signature = 'emails:block
                            {email? : Адрес; без него — показать весь список}
                            {--reason= : Причина блокировки}
                            {--remove : Убрать адрес из чёрного списка}';

    protected $description = 'Чёрный список e-mail: показать, добавить (--reason), убрать (--remove)';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! $email) {
            $rows = BlockedEmail::orderBy('email')->get(['email', 'reason', 'created_at']);

            if ($rows->isEmpty()) {
                $this->info('Чёрный список пуст.');

                return self::SUCCESS;
            }

            $this->table(
                ['E-mail', 'Причина', 'Добавлен'],
                $rows->map(fn (BlockedEmail $r) => [
                    $r->email,
                    $r->reason ?? '',
                    $r->created_at?->format('d.m.Y H:i') ?? '',
                ])->all(),
            );

            return self::SUCCESS;
        }

        if ($this->option('remove')) {
            $removed = BlockedEmail::unblock($email);
            $this->info($removed
                ? "Адрес {$email} убран из чёрного списка."
                : "Адреса {$email} в чёрном списке не было.");

            return self::SUCCESS;
        }

        BlockedEmail::block($email, $this->option('reason'));
        $this->info("Адрес {$email} добавлен в чёрный список: покупка, регистрация и восстановление пароля для него закрыты.");

        return self::SUCCESS;
    }
}
