<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoardingPoint;
use App\Models\BoardingPointStation;
use App\Services\BoardingPointInspector;
use App\Services\StationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Справочник пунктов посадки: остановки на трассе, с которых сайт продаёт
 * билеты (docs/BOARDING_POINTS.md).
 *
 * Пункт описывается названием для покупателя и привязками к станционным
 * базам: от Дросково в Ливны везёт рейс из базы Орла, а в Орёл — рейс из
 * базы Ливен, и это две разные привязки одного пункта.
 */
class BoardingPointController extends Controller
{
    public function __construct(
        private readonly StationRegistry $stations,
        private readonly BoardingPointInspector $inspector,
    ) {}

    public function index(): JsonResponse
    {
        $points = BoardingPoint::with('stations')->orderBy('inlist')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'points' => $points->map(fn (BoardingPoint $p) => $this->present($p))->all(),
                // Станции для селекта привязки — только действующие.
                'stations' => collect($this->stations->all())
                    ->map(fn (array $s, string $code) => [
                        'awtcod' => (int) $code,
                        'name' => $s['name'],
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $point = DB::transaction(function () use ($data) {
            $point = BoardingPoint::create($this->attributes($data));
            $this->saveStations($point, $data['stations'] ?? []);

            return $point;
        });

        return response()->json([
            'success' => true,
            'data' => $this->present($point->fresh('stations')),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $point = BoardingPoint::findOrFail($id);
        $data = $this->validated($request, $point);

        DB::transaction(function () use ($point, $data) {
            $point->update($this->attributes($data));
            $this->saveStations($point, $data['stations'] ?? []);
        });

        return response()->json([
            'success' => true,
            'data' => $this->present($point->fresh('stations')),
        ]);
    }

    /**
     * Удаление пункта посадки.
     *
     * Заказы с ним остаются целыми: в заказе лежат и awtcod станции, и
     * название остановки, поэтому возврат и отчёты по прежним покупкам
     * работают без справочника. Пропадает только возможность КУПИТЬ от
     * этой остановки — поэтому удалять безопасно, а «выключить» (is_active)
     * чаще правильнее: ссылки на расписание остаются рабочими.
     */
    public function destroy(int $id): JsonResponse
    {
        BoardingPoint::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Проверка привязки по живой базе станции: куда продаются билеты от
     * остановки и что станции ещё нужно настроить.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'awtcod' => ['required', 'integer', 'min:1', 'max:32767'],
            'station_name' => ['required', 'string', 'max:64'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->inspector->inspect((int) $data['awtcod'], $data['station_name']),
        ]);
    }

    /** Подсказка названий остановок станции при вводе привязки. */
    public function stops(Request $request, int $awtcod): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->inspector->stops($awtcod, trim((string) ($data['search'] ?? ''))),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BoardingPoint $point = null): array
    {
        return $request->validate([
            /*
             * slug уходит в адреса сайта и в заказы — только латиница,
             * цифры и дефис. У существующего пункта его менять можно
             * (ссылки осиротеют, зато опечатку исправить реально), но
             * уникальность проверяем всегда.
             */
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('boarding_points', 'slug')->ignore($point?->id),
            ],
            'name' => ['required', 'string', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'show_on_home' => ['sometimes', 'boolean'],
            'show_in_schedule' => ['sometimes', 'boolean'],
            'inlist' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'note' => ['nullable', 'string', 'max:255'],

            'stations' => ['sometimes', 'array', 'max:20'],
            // AWTCOD в базе станции — SMALLINT, предел не наш.
            'stations.*.awtcod' => ['required', 'integer', 'min:1', 'max:32767'],
            'stations.*.station_name' => ['required', 'string', 'max:64'],
            'stations.*.is_active' => ['sometimes', 'boolean'],
            'stations.*.inlist' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'stations.*.excluded_destinations' => ['sometimes', 'nullable', 'array', 'max:200'],
            'stations.*.excluded_destinations.*' => ['string', 'max:64'],
        ], [], [
            'slug' => 'ключ',
            'name' => 'название',
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'slug' => $data['slug'],
            'name' => $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'show_on_home' => (bool) ($data['show_on_home'] ?? true),
            'show_in_schedule' => (bool) ($data['show_in_schedule'] ?? true),
            'inlist' => (int) ($data['inlist'] ?? 0),
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * Привязки заменяются списком целиком — как у профилей станции и
     * контактов сайта: страница присылает то, что видит сотрудник.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function saveStations(BoardingPoint $point, array $rows): void
    {
        $keep = [];

        foreach ($rows as $i => $row) {
            $binding = BoardingPointStation::updateOrCreate(
                [
                    'boarding_point_id' => $point->id,
                    'awtcod' => (int) $row['awtcod'],
                    'station_name' => $row['station_name'],
                ],
                [
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'inlist' => (int) ($row['inlist'] ?? $i),
                    'excluded_destinations' => $row['excluded_destinations'] ?? null,
                ],
            );

            $keep[] = $binding->id;
        }

        $point->stations()->whereNotIn('id', $keep ?: [0])->delete();
    }

    /** @return array<string, mixed> */
    private function present(BoardingPoint $point): array
    {
        return [
            'id' => $point->id,
            'slug' => $point->slug,
            'name' => $point->name,
            'is_active' => $point->is_active,
            'show_on_home' => $point->show_on_home,
            'show_in_schedule' => $point->show_in_schedule,
            'inlist' => $point->inlist,
            'note' => $point->note,
            'stations' => $point->stations->map(fn (BoardingPointStation $s) => [
                'id' => $s->id,
                'awtcod' => $s->awtcod,
                'station_name' => $s->station_name,
                'station_title' => $this->stations->name($s->awtcod),
                'is_active' => $s->is_active,
                'inlist' => $s->inlist,
                'excluded_destinations' => $s->excluded_destinations ?? [],
            ])->all(),
        ];
    }
}
