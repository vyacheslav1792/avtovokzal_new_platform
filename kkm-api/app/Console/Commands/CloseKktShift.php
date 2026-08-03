<?php

namespace App\Console\Commands;

use App\Services\KkmServerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Ночное закрытие смены ККТ (Z-отчёт) по расписанию — чтобы смена
 * никогда не доживала до блокировки ФН по 24 часам (54-ФЗ).
 * Новая смена откроется автоматически первым чеком.
 */
class CloseKktShift extends Command
{
    protected $signature = 'kkm:close-shift';

    protected $description = 'Закрыть смену ККТ (Z-отчёт), если она открыта';

    public function handle(KkmServerClient $kkm): int
    {
        $info = $kkm->getKktInfo();
        $state = (int) ($info['Info']['SessionState'] ?? 0);

        if ($state === 1) {
            $this->info('Смена уже закрыта — Z-отчёт не требуется.');

            return self::SUCCESS;
        }

        $result = $kkm->closeShift((string) Str::uuid());
        $status = (int) ($result['Status'] ?? KkmServerClient::STATUS_ERROR);

        if ($status !== KkmServerClient::STATUS_OK) {
            $this->error('Не удалось закрыть смену: '.($result['Error'] ?? 'статус '.$status));
            report(new \RuntimeException('Ночное закрытие смены ККТ не удалось: '.($result['Error'] ?? 'статус '.$status)));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Смена %s закрыта (чек Z-отчёта № %s).',
            $result['SessionNumber'] ?? '?',
            $result['CheckNumber'] ?? '?',
        ));

        return self::SUCCESS;
    }
}
