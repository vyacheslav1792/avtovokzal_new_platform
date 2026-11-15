<?php

namespace App\Console\Commands;

use App\Models\KkmServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Завести или поправить вариант подключения к KkmServer из командной
 * строки — чтобы при развёртывании перенести настройки из `.env` kkm-api
 * в справочник АИС, не вводя их руками.
 *
 * Дальше ими управляют в АИС (страница «Фискальный сервер»).
 */
class SetKkmServer extends Command
{
    protected $signature = 'kkm:server-set
        {--slot=1 : номер варианта (1..3)}
        {--label= : название варианта}
        {--url= : адрес KkmServer, например http://127.0.0.1:5893}
        {--user= : пользователь KkmServer}
        {--password= : пароль KkmServer}
        {--num-device=1 : номер устройства ККТ}
        {--timeout=30 : таймаут команды, секунд}
        {--cashier= : имя кассира в чеке}
        {--notes= : пояснение}
        {--activate : сделать этот вариант действующим}';

    protected $description = 'Задать вариант подключения к KkmServer в справочнике АИС';

    public function handle(): int
    {
        $slot = (int) $this->option('slot');

        if ($slot < 1 || $slot > KkmServer::SLOTS) {
            $this->error('Номер варианта — от 1 до '.KkmServer::SLOTS.'.');

            return self::FAILURE;
        }

        $existing = KkmServer::where('slot', $slot)->first();
        $url = (string) ($this->option('url') ?: $existing->url ?? '');

        if ($url === '') {
            $this->error('Укажите --url: адрес KkmServer.');

            return self::FAILURE;
        }

        $server = DB::transaction(function () use ($slot, $existing, $url) {
            $server = KkmServer::updateOrCreate(['slot' => $slot], [
                'label' => (string) ($this->option('label') ?: $existing->label ?? "Вариант {$slot}"),
                'url' => rtrim($url, '/'),
                'user' => $this->option('user') ?? $existing->user ?? null,
                // Пустой пароль не стираем: команду запускают и просто
                // чтобы сменить адрес.
                'password' => $this->option('password') ?: ($existing->password ?? null),
                'num_device' => (int) $this->option('num-device'),
                'command_timeout' => (int) $this->option('timeout'),
                'cashier_name' => $this->option('cashier') ?: ($existing->cashier_name ?? null),
                'notes' => $this->option('notes') ?: ($existing->notes ?? null),
                'is_active' => (bool) ($existing->is_active ?? false),
            ]);

            if ($this->option('activate')) {
                KkmServer::query()->update(['is_active' => false]);
                $server->update(['is_active' => true]);
            }

            return $server;
        });

        $this->info("Вариант {$server->slot} «{$server->label}»: {$server->url}, устройство "
            .$server->num_device.($server->is_active ? ' — ДЕЙСТВУЮЩИЙ' : ''));

        if (! KkmServer::where('is_active', true)->exists()) {
            $this->warn('Действующего варианта нет — фискализация выключена, чеки пробиваться не будут.');
        }

        return self::SUCCESS;
    }
}
