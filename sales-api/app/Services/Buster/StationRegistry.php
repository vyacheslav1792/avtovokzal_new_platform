<?php

namespace App\Services\Buster;

use Illuminate\Http\Exceptions\HttpResponseException;

class StationRegistry
{
    /** @return array<string, array> */
    public function all(): array
    {
        return config('stations.list');
    }

    public function get(int|string $awtcod): array
    {
        $station = config('stations.list')[(string) $awtcod] ?? null;

        if (! $station) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'error' => "Станция с кодом {$awtcod} не найдена",
                'error_code' => 'station_not_found',
            ], 404));
        }

        return $station + ['awtcod' => (int) $awtcod];
    }

    public function name(int|string $awtcod): ?string
    {
        return config('stations.list')[(string) $awtcod]['name'] ?? null;
    }
}
