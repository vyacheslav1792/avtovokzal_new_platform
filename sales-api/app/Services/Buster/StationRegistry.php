<?php

namespace App\Services\Buster;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Реестр станций. Источник — справочник в БД АИС (таблица `stations`,
 * правится сотрудником в АИС); пока он пуст, работает прежний файл
 * shared/stations.php — см. StationCatalog.
 *
 * Отключённой станции (`is_active = false`) здесь нет вовсе: запрос по
 * её коду отвечает «станция не найдена», как и по незнакомому коду.
 */
class StationRegistry
{
    public function __construct(private readonly StationCatalog $catalog) {}

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->catalog->entries();
    }

    public function get(int|string $awtcod): array
    {
        $station = $this->all()[(string) $awtcod] ?? null;

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
        return $this->all()[(string) $awtcod]['name'] ?? null;
    }

    /**
     * Продажа по станции включена? Выключатель станционный — он про одну
     * станцию, в отличие от общего выключателя продаж сайта (его держит
     * backend по настройкам в АИС).
     *
     * Станции из файла считаются включёнными: там таких полей нет, и
     * локальный контур не должен остаться без продажи.
     */
    public function salesEnabled(int|string $awtcod): bool
    {
        return (bool) ($this->all()[(string) $awtcod]['sales_enabled'] ?? true);
    }

    public function refundsEnabled(int|string $awtcod): bool
    {
        return (bool) ($this->all()[(string) $awtcod]['refunds_enabled'] ?? true);
    }

    /** Отказ продажи по станции — до любых обращений к её базе. */
    public function assertSalesEnabled(int|string $awtcod): void
    {
        $station = $this->get($awtcod);

        if ($this->salesEnabled($awtcod)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => "Продажа билетов по станции «{$station['name']}» сейчас недоступна. "
                .'Билеты продаются в кассах автовокзала.',
            'error_code' => 'station_sales_disabled',
        ], 422));
    }

    /** Отказ возврата по станции (админский возврат его не слушает). */
    public function assertRefundsEnabled(int|string $awtcod): void
    {
        $station = $this->get($awtcod);

        if ($this->refundsEnabled($awtcod)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => "Возврат билетов по станции «{$station['name']}» сейчас недоступен. "
                .'Обратитесь в кассу автовокзала.',
            'error_code' => 'station_refunds_disabled',
        ], 422));
    }
}
