<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Клиент API продаж (отдельный сервис sales-api).
 * Высокоуровневое API не ходит в базы автостанций напрямую — только сюда.
 */
class SalesApiClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.sales_api.url'))
            ->withToken(config('services.sales_api.token'))
            ->timeout(config('services.sales_api.timeout'))
            ->acceptJson();
    }

    public function stations(): array
    {
        return $this->call(fn () => $this->http()->get('stations'));
    }

    public function destinations(int $awtcod): array
    {
        return $this->call(fn () => $this->http()->get("stations/{$awtcod}/destinations"));
    }

    public function trips(int $awtcod, string $station, string $date, bool $forSale = true): array
    {
        return $this->call(fn () => $this->http()->get('trips', [
            'awtcod' => $awtcod,
            'station' => $station,
            'date' => $date,
            'for_sale' => $forSale ? 1 : 0,
        ]));
    }

    public function places(int $awtcod, string $date, int $napcod, int $raicecod): array
    {
        return $this->call(fn () => $this->http()->get('trips/places', [
            'awtcod' => $awtcod,
            'date' => $date,
            'napcod' => $napcod,
            'raicecod' => $raicecod,
        ]));
    }

    public function reserve(array $payload): array
    {
        return $this->call(fn () => $this->http()->post('reservations', $payload));
    }

    public function sell(array $payload): array
    {
        return $this->call(fn () => $this->http()->post('sales', $payload));
    }

    public function refund(array $payload): array
    {
        return $this->call(fn () => $this->http()->post('refunds', $payload));
    }

    /** Строка EVRYRAICE (в т.ч. флаги CANCELED/DISRUPT — отменён/сорван). */
    public function tripStatus(int $awtcod, string $date, int $napcod, int $raicecod): array
    {
        return $this->call(fn () => $this->http()->get('trips/status', [
            'awtcod' => $awtcod,
            'date' => $date,
            'napcod' => $napcod,
            'raicecod' => $raicecod,
        ]));
    }

    private function call(callable $request): array
    {
        $response = $request();

        $data = $response->json();

        if (! is_array($data)) {
            throw new SalesApiException('Некорректный ответ API продаж', 'bad_response');
        }

        if (! ($data['success'] ?? false)) {
            throw new SalesApiException(
                $data['error'] ?? $data['message'] ?? 'Ошибка API продаж',
                $data['error_code'] ?? 'sales_api_error',
            );
        }

        return $data;
    }
}
