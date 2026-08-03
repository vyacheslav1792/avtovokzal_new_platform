<?php

namespace App\Services;

/**
 * Реестр станций — общий конфиг shared/stations.php монорепозитория
 * (тот же, что у sales-api; в legacy $AWT дублировался трижды).
 */
class StationRegistry
{
    /** @return array<string, array> все станции */
    public function all(): array
    {
        return config('stations.list');
    }

    public function name(int|string $awtcod): string
    {
        if ($awtcod === 'all') {
            return 'Все';
        }

        return config('stations.list')[(string) $awtcod]['name'] ?? (string) $awtcod;
    }

    /**
     * Подмножество станций по фильтру отчёта: 'all'/пусто — все,
     * иначе одна выбранная (аналог legacy ais_awt_subset).
     *
     * @return array<string, array>
     */
    public function subset(?string $stan): array
    {
        if ($stan === null || $stan === '' || $stan === 'all') {
            return $this->all();
        }

        $station = config('stations.list')[$stan] ?? null;

        return $station ? [$stan => $station] : [];
    }

    /** Опции для фильтра-селекта «Хозяйство» (с пунктом «Все»). */
    public function selectOptions(): array
    {
        $options = [['value' => 'all', 'label' => 'Все']];

        foreach ($this->all() as $code => $station) {
            $options[] = ['value' => (string) $code, 'label' => $station['name']];
        }

        return $options;
    }
}
