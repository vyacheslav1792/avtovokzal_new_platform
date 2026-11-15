<?php

namespace App\Services;

/**
 * Реестр станций. Источник — справочник в БД (таблица `stations`,
 * правится в АИС); пока он пуст, работает прежний файл
 * shared/stations.php — см. StationCatalog.
 *
 * Отключённых станций (`is_active = false`) здесь нет вовсе: для отчётов
 * и сервисов такой станции не существует.
 */
class StationRegistry
{
    public function __construct(private readonly StationCatalog $catalog) {}

    /** @return array<string, array> все станции */
    public function all(): array
    {
        return $this->catalog->entries();
    }

    public function name(int|string $awtcod): string
    {
        if ($awtcod === 'all') {
            return 'Все';
        }

        return $this->all()[(string) $awtcod]['name'] ?? (string) $awtcod;
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

        $station = $this->all()[$stan] ?? null;

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
