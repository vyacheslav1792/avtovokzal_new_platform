<?php

namespace App\Services;

use App\Models\StationDestination;
use Illuminate\Support\Facades\Log;

/**
 * Пункты назначения станций: обновление из API продаж и выдача сайту.
 *
 * Список «Куда» меняется от случая к случаю (новая остановка, закрытая
 * касса), а читался из Firebird на КАЖДОЕ открытие формы поиска — две
 * сотни строк из KS_STATIONS с сортировкой поверх. Теперь он лежит в
 * Postgres и обновляется по расписанию (ofelia, 07:00 и 10:00 МСК) и
 * кнопкой в АИС.
 *
 * Правило, ради которого всё и сделано: **неудачный запрос не стирает
 * прежний список**. Пустое «Куда» означает, что покупатель не может даже
 * начать поиск, поэтому вчерашние данные лучше, чем никакие.
 */
class DestinationService
{
    public function __construct(private readonly SalesApiClient $salesApi) {}

    /**
     * Ответ для сайта в том же виде, что раньше отдавал API продаж.
     *
     * Станции нет в кэше (добавили на станции, обновление ещё не
     * проходило) — читаем живьём: форма не должна остаться пустой из-за
     * того, что мы чего-то не успели обновить.
     *
     * @return array<string, mixed>
     */
    public function forStation(int $awtcod): array
    {
        $row = StationDestination::where('awtcod', $awtcod)->first();

        if ($row === null) {
            return $this->salesApi->destinations($awtcod);
        }

        return [
            'success' => true,
            'data' => $row->destinations,
            // Когда список снят со станции — по нему видно, насколько он свеж.
            'generated_at' => $row->generated_at?->toIso8601String(),
        ];
    }

    /**
     * Обновить пункты назначения всех станций.
     *
     * @return array<int, array{awtcod: int, name: string, destinations: ?int, error: ?string}>
     */
    public function syncAll(): array
    {
        $result = [];

        foreach ($this->salesApi->stations()['data'] as $station) {
            $result[] = $this->sync((int) $station['awtcod'], (string) $station['name']);
        }

        return $result;
    }

    /**
     * Обновить одну станцию. Название необязательно: при запуске по
     * `--awtcod` его неоткуда взять, а записывать в таблицу числовой код
     * вместо названия нельзя — админка и журнал показывают именно его.
     *
     * @return array{awtcod: int, name: string, destinations: ?int, error: ?string}
     */
    public function sync(int $awtcod, ?string $name = null): array
    {
        $name = $this->resolveName($awtcod, $name);

        try {
            $response = $this->salesApi->destinations($awtcod);
            $destinations = $response['data'] ?? [];

            // Пустой ответ — это тоже повод НЕ трогать прежний список:
            // станция без пунктов назначения не бывает, а вот сорванный
            // запрос, отдавший пустоту, — бывает.
            if ($destinations === []) {
                throw new \RuntimeException('Станция вернула пустой список пунктов назначения');
            }

            StationDestination::updateOrCreate(
                ['awtcod' => $awtcod],
                [
                    'station_name' => $name,
                    'destinations' => $destinations,
                    'destinations_count' => count($destinations),
                    'generated_at' => now(),
                    'synced_at' => now(),
                    'sync_error' => null,
                ],
            );

            return [
                'awtcod' => $awtcod,
                'name' => $name,
                'destinations' => count($destinations),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Не удалось обновить пункты назначения станции', [
                'awtcod' => $awtcod,
                'error' => $e->getMessage(),
            ]);

            // Прежний список остаётся как есть — меняем только отметки.
            StationDestination::where('awtcod', $awtcod)->update([
                'synced_at' => now(),
                'sync_error' => $e->getMessage(),
            ]);

            return ['awtcod' => $awtcod, 'name' => $name, 'destinations' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Название станции: что передали, иначе прежнее из таблицы, иначе
     * из списка станций. Список может не ответить — тогда остаётся код,
     * и это всё равно лучше, чем уронить обновление из-за названия.
     */
    private function resolveName(int $awtcod, ?string $name): string
    {
        if ($name !== null && $name !== '' && ! ctype_digit($name)) {
            return $name;
        }

        $saved = StationDestination::where('awtcod', $awtcod)->value('station_name');

        if ($saved) {
            return $saved;
        }

        try {
            foreach ($this->salesApi->stations()['data'] as $station) {
                if ((int) $station['awtcod'] === $awtcod) {
                    return (string) $station['name'];
                }
            }
        } catch (\Throwable) {
            // Названия не узнали — обновление это не отменяет.
        }

        return (string) $awtcod;
    }

    /**
     * Состояние кэша для админки: по станции — сколько пунктов, когда
     * снято, когда обновлялось, с какой ошибкой.
     *
     * @return array<int, array<string, mixed>>
     */
    public function status(): array
    {
        return StationDestination::orderBy('station_name')
            ->get()
            ->map(fn (StationDestination $row) => [
                'awtcod' => (int) $row->awtcod,
                'name' => $row->station_name,
                'count' => (int) $row->destinations_count,
                'generated_at' => $row->generated_at?->toIso8601String(),
                'synced_at' => $row->synced_at?->toIso8601String(),
                'sync_error' => $row->sync_error,
            ])
            ->all();
    }
}
