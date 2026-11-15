<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Клиент KKM API (отдельный сервис-прослойка к KkmServer).
 * external_id — ключ идемпотентности: повторная отправка того же
 * ключа никогда не пробивает второй чек.
 */
class KkmApiClient
{
    /**
     * @param  array[]  $items  [{name, price, quantity?}]
     * @param  int|null  $timeout  сколько ждать ответ; null — таймаут сервиса.
     *                             Короткий нужен первой, синхронной попытке
     *                             при оформлении: покупатель ждёт билет.
     * @return array data чека: status, check_number, fiscal_sign, …
     */
    public function registerReceipt(
        string $externalId,
        string $type,
        string $email,
        array $items,
        float $electronicPayment,
        ?int $timeout = null,
    ): array {
        $request = Http::baseUrl(config('services.kkm_api.url'))
            ->withToken(config('services.kkm_api.token'))
            ->timeout($timeout ?? config('services.kkm_api.timeout'));

        /*
         * У синхронной попытки ограничиваем и УСТАНОВКУ соединения: без
         * этого недоступный сервис съедал вдвое больше времени, чем общий
         * таймаут (DNS резолвится отдельно для IPv4 и IPv6 — 5 с ожидания
         * превращались в 10, и покупатель столько же ждал билет).
         */
        if ($timeout !== null) {
            $request = $request->connectTimeout(max(1, (int) ceil($timeout / 3)));
        }

        $response = $request
            ->acceptJson()
            ->post('receipts', [
                'external_id' => $externalId,
                'type' => $type,
                'email' => $email,
                'items' => $items,
                'electronic_payment' => round($electronicPayment, 2),
            ]);

        $data = $response->json();

        if (! is_array($data) || ! ($data['success'] ?? false)) {
            throw new SalesApiException(
                is_array($data) ? ($data['error'] ?? 'Ошибка KKM API') : 'KKM API недоступен',
                is_array($data) ? ($data['error_code'] ?? 'kkm_api_error') : 'kkm_api_unavailable',
            );
        }

        return $data['data'];
    }
}
