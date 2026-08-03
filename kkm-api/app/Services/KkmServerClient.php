<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Низкоуровневый клиент KkmServer (https://kkmserver.ru/KkmServer):
 * все команды идут в POST /Execute одним JSON.
 */
class KkmServerClient
{
    /** Статусы KkmServer. */
    public const STATUS_OK = 0;

    public const STATUS_RUNNING = 1;

    public const STATUS_ERROR = 2;

    public const STATUS_NOT_FOUND = 3;

    public const STATUS_NOT_RUN = 4;

    public function execute(array $command): array
    {
        $response = Http::baseUrl(config('kkm.url'))
            ->withBasicAuth(config('kkm.user'), config('kkm.password'))
            ->timeout(config('kkm.command_timeout') + 15)
            ->acceptJson()
            ->post('/Execute', $command);

        $data = $response->json();

        if (! is_array($data)) {
            throw new KkmException(
                'KkmServer недоступен или вернул некорректный ответ (HTTP '.$response->status().')',
                'kkm_unavailable',
            );
        }

        return $data;
    }

    /**
     * Регистрация фискального чека.
     *
     * @param  string  $idCommand  IdCommand — идемпотентность внутри KkmServer
     * @param  int  $typeCheck  0 — приход (продажа), 1 — возврат прихода
     * @param  array[]  $items  [{name, price, quantity}]
     */
    public function registerCheck(
        string $idCommand,
        int $typeCheck,
        string $email,
        array $items,
        float $electronicPayment,
    ): array {
        $checkStrings = array_map(fn (array $item) => [
            'Register' => [
                'Name' => mb_substr($item['name'], 0, 64),
                'Quantity' => (float) ($item['quantity'] ?? 1),
                'Price' => round((float) $item['price'], 2),
                'Amount' => round((float) $item['price'] * (float) ($item['quantity'] ?? 1), 2),
                'Department' => 0,
                'Tax' => -1,                     // без НДС
                'SignMethodCalculation' => 4,    // полный расчёт
                'SignCalculationObject' => 4,    // услуга
            ],
        ], $items);

        return $this->execute([
            'Command' => 'RegisterCheck',
            'NumDevice' => config('kkm.num_device'),
            'IdCommand' => $idCommand,
            'IsFiscalCheck' => true,
            'TypeCheck' => $typeCheck,
            'NotPrint' => true,
            'Timeout' => config('kkm.command_timeout'),
            'CashierName' => config('kkm.cashier_name'),
            'ClientAddress' => $email,
            'CheckStrings' => $checkStrings,
            'ElectronicPayment' => round($electronicPayment, 2),
        ]);
    }

    /** Результат ранее отправленной команды по её IdCommand. */
    public function getResult(string $idCommand): array
    {
        return $this->execute([
            'Command' => 'GetRezult',
            'IdCommand' => $idCommand,
        ]);
    }

    /** Данные и состояние ККТ (Info.SessionState: 1 закрыта, 2 открыта, 3 истекла >24ч). */
    public function getKktInfo(): array
    {
        return $this->execute([
            'Command' => 'GetDataKKT',
            'NumDevice' => config('kkm.num_device'),
        ]);
    }

    /** Закрытие смены (Z-отчёт). */
    public function closeShift(string $idCommand): array
    {
        return $this->execute([
            'Command' => 'CloseShift',
            'NumDevice' => config('kkm.num_device'),
            'IdCommand' => $idCommand,
            'CashierName' => config('kkm.cashier_name'),
            'NotPrint' => true,
            'Timeout' => config('kkm.command_timeout'),
        ]);
    }
}
