<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StationProfile;
use App\Services\ConnectionTester;
use App\Services\StationCatalog;
use App\Services\StationUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Справочник автостанций: раньше его правили в файле (shared/stations.php,
 * а в legacy — $AWT прямо в коде, причём в трёх местах), теперь из АИС.
 *
 * Пароли баз наружу НЕ отдаются: в списке только признак «задан». Чтобы
 * сменить пароль, его присылают явно — пустое поле означает «оставить
 * прежний».
 */
class StationController extends Controller
{
    public function __construct(
        private readonly StationCatalog $catalog,
        private readonly ConnectionTester $tester,
        private readonly StationUsage $usage,
    ) {}

    public function index(): JsonResponse
    {
        $stations = Station::with('profiles')->orderBy('inlist')->orderBy('awtcod')->get();

        // Что накопилось по станциям — чтобы кнопка «Удалить» не обещала
        // того, чего сервер не сделает. Недоступная БД сайта не должна
        // ломать список: тогда считаем, что про данные ничего не знаем.
        try {
            $usage = $this->usage->map($stations->pluck('awtcod')->all());
            $usageKnown = true;
        } catch (\Throwable $e) {
            report($e);
            $usage = [];
            $usageKnown = false;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stations' => $stations->map(
                    fn (Station $s) => $this->present($s, $usage[$s->awtcod] ?? [], $usageKnown),
                )->all(),
                'usage_known' => $usageKnown,
                'profile_slots' => Station::PROFILE_SLOTS,
                // Пока справочник пуст, сервисы читают файл shared/stations.php —
                // админке об этом стоит сказать прямо.
                'from_file' => $stations->isEmpty(),
                'file_stations' => $stations->isEmpty() ? array_keys((array) config('stations.list', [])) : [],
            ],
        ]);
    }

    /** Создание станции: код свободен, подключение можно задать сразу. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Предел 32767 — не наш: AWTCOD в базе станции SMALLINT, и
            // число больше встречает арифметической ошибкой Firebird.
            'awtcod' => ['required', 'integer', 'min:1', 'max:32767', 'unique:stations,awtcod'],
            ...$this->stationRules(),
        ], $this->messages());

        $station = DB::transaction(function () use ($data) {
            $station = Station::create($this->stationAttributes($data) + ['awtcod' => (int) $data['awtcod']]);
            $this->saveProfiles($station, $data['profiles'] ?? []);

            return $station;
        });

        $this->catalog->forget();

        return response()->json(['success' => true, 'data' => $this->present($station->load('profiles'))], 201);
    }

    public function update(Request $request, int $awtcod): JsonResponse
    {
        $station = Station::with('profiles')->findOrFail($awtcod);

        $data = $request->validate($this->stationRules(), $this->messages());

        DB::transaction(function () use ($station, $data) {
            $station->update($this->stationAttributes($data));
            $this->saveProfiles($station, $data['profiles'] ?? []);
        });

        $this->catalog->forget();

        return response()->json(['success' => true, 'data' => $this->present($station->fresh('profiles'))]);
    }

    /**
     * Быстрое переключение варианта подключения — отдельной ручкой, а не
     * правкой всей станции: в legacy для этого правили $IP_MODE в коде и
     * выкладывали файл на боевой сервер.
     */
    public function switchProfile(Request $request, int $awtcod): JsonResponse
    {
        $station = Station::with('profiles')->findOrFail($awtcod);

        $data = $request->validate([
            'slot' => ['required', 'integer', 'min:1', 'max:'.Station::PROFILE_SLOTS],
        ]);

        if (! $station->profiles->contains('slot', (int) $data['slot'])) {
            return response()->json([
                'success' => false,
                'error' => 'Такой вариант подключения у станции не настроен.',
            ], 422);
        }

        $station->update(['active_profile' => (int) $data['slot']]);
        $this->catalog->forget();

        return response()->json(['success' => true, 'data' => $this->present($station->fresh('profiles'))]);
    }

    /** Проверка связи: подключаемся к базе станции и спрашиваем её время. */
    public function test(Request $request, int $awtcod): JsonResponse
    {
        $station = Station::with('profiles')->findOrFail($awtcod);

        $slot = (int) $request->input('slot', $station->active_profile);
        $profile = $station->profiles->firstWhere('slot', $slot);

        if (! $profile) {
            return response()->json([
                'success' => false,
                'error' => 'Такой вариант подключения у станции не настроен.',
            ], 422);
        }

        $result = $this->tester->station($profile);

        return response()->json([
            'success' => true,
            'data' => ['result' => $result, 'station' => $this->present($station->fresh('profiles'))],
        ]);
    }

    /**
     * Удаление станции — только пока по ней нет данных.
     *
     * Станцию, которая работала, удалять нельзя: заказы сайта и собранные
     * продажи остались бы строками с кодом, которого нет ни в одном
     * справочнике, а в отчётах — пустым названием станции. Такую станцию
     * выключают (`is_active`), и для сайта с отчётами её «как будто нет».
     * Удаление нужно другому: станции, заведённой по ошибке или так и не
     * включённой в работу.
     */
    public function destroy(int $awtcod): JsonResponse
    {
        $station = Station::findOrFail($awtcod);

        // Проверить обязательно НА СЕРВЕРЕ: кнопку на странице можно и не
        // нажимать — запрос приходит и напрямую.
        try {
            $found = $this->usage->forStation($awtcod);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось проверить, есть ли данные по станции, — удаление отменено.',
            ], 503);
        }

        if ($found !== []) {
            return response()->json([
                'success' => false,
                'error' => 'По станции «'.$station->name.'» есть данные ('
                    .$this->usage->describe($found)
                    .'), удалить её нельзя. Чтобы убрать станцию из работы, выключите её.',
                'data_found' => $found,
            ], 422);
        }

        // Варианты подключения уходят каскадом — это не данные станции,
        // а её реквизиты.
        $station->delete();
        $this->catalog->forget();

        return response()->json(['success' => true]);
    }

    /**
     * Перенести станции из файла shared/stations.php в справочник —
     * разовая операция при переходе (и страховка, если справочник
     * случайно опустеет).
     */
    public function importFromFile(): JsonResponse
    {
        $imported = 0;

        foreach ((array) config('stations.list', []) as $code => $entry) {
            if (Station::whereKey((int) $code)->exists()) {
                continue;
            }

            DB::transaction(function () use ($code, $entry) {
                $station = Station::create([
                    'awtcod' => (int) $code,
                    'name' => $entry['name'] ?? (string) $code,
                    'buh' => $entry['buh'] ?? null,
                    'buh_st' => $entry['buh_st'] ?? null,
                    'buh_ksr' => $entry['buh_ksr'] ?? null,
                    'stationkey' => $entry['stationkey'] ?? null,
                    'inlist' => (int) ($entry['inlist'] ?? 0),
                    'is_active' => true,
                    // Продажа и возврат по станции включаются отдельно и
                    // осознанно — на переносе ничего не включаем.
                    'sales_enabled' => false,
                    'refunds_enabled' => false,
                    'active_profile' => 1,
                ]);

                StationProfile::create([
                    'station_awtcod' => $station->awtcod,
                    'slot' => 1,
                    'label' => 'Из файла конфигурации',
                    'db_host' => $entry['db_host'] ?? '',
                    'db_port' => (int) ($entry['db_port'] ?? 3050),
                    'db_name' => $entry['db_name'] ?? 'buster',
                    'db_user' => $entry['db_user'] ?? 'SYSDBA',
                    'db_password' => (string) ($entry['db_password'] ?? ''),
                ]);
            });

            $imported++;
        }

        $this->catalog->forget();

        return response()->json(['success' => true, 'data' => ['imported' => $imported]]);
    }

    /**
     * Станция для админки. Признаки удаления идут ВМЕСТЕ со станцией:
     * страница подменяет строку ответом сервера после каждой операции, и
     * без них кнопка «Удалить» после переключения профиля пропадала бы.
     *
     * @param  array<string, int>|null  $found  null — посчитать здесь
     * @return array<string, mixed>
     */
    private function present(Station $station, ?array $found = null, bool $usageKnown = true): array
    {
        if ($found === null) {
            try {
                // Перечень, а не счётчики: строки считаются только при
                // отказе в удалении — там число и объясняет отказ.
                $found = $this->usage->map([$station->awtcod])[$station->awtcod] ?? [];
            } catch (\Throwable $e) {
                report($e);
                $found = [];
                $usageKnown = false;
            }
        }

        return [
            'data_found' => $found,
            'can_delete' => $usageKnown && $found === [],
            'awtcod' => $station->awtcod,
            'name' => $station->name,
            'buh' => $station->buh,
            'buh_st' => $station->buh_st,
            'buh_ksr' => $station->buh_ksr,
            'stationkey' => $station->stationkey,
            'inlist' => $station->inlist,
            'is_active' => $station->is_active,
            'sales_enabled' => $station->sales_enabled,
            'refunds_enabled' => $station->refunds_enabled,
            'active_profile' => $station->active_profile,
            'profiles' => $station->profiles->map(fn (StationProfile $p) => [
                'slot' => $p->slot,
                'label' => $p->label,
                'db_host' => $p->db_host,
                'db_port' => $p->db_port,
                'db_name' => $p->db_name,
                'db_user' => $p->db_user,
                // Сам пароль не отдаём — только то, задан ли он.
                'has_password' => $p->db_password !== '',
                'notes' => $p->notes,
                'checked_at' => $p->checked_at?->format('d.m.Y H:i'),
                'check_ok' => $p->check_ok,
                'check_error' => $p->check_error,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function stationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'buh' => ['nullable', 'string', 'max:16'],
            'buh_st' => ['nullable', 'string', 'max:128'],
            'buh_ksr' => ['nullable', 'string', 'max:128'],
            'stationkey' => ['nullable', 'string', 'max:128'],
            'inlist' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'sales_enabled' => ['required', 'boolean'],
            'refunds_enabled' => ['required', 'boolean'],
            'active_profile' => ['required', 'integer', 'min:1', 'max:'.Station::PROFILE_SLOTS],

            'profiles' => ['array', 'max:'.Station::PROFILE_SLOTS],
            'profiles.*.slot' => ['required', 'integer', 'min:1', 'max:'.Station::PROFILE_SLOTS],
            'profiles.*.label' => ['required', 'string', 'max:64'],
            'profiles.*.db_host' => ['required', 'string', 'max:128'],
            'profiles.*.db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'profiles.*.db_name' => ['required', 'string', 'max:255'],
            'profiles.*.db_user' => ['required', 'string', 'max:64'],
            // Пустой пароль = «не менять»: наружу мы его не отдавали,
            // поэтому форма и не может его вернуть.
            'profiles.*.db_password' => ['nullable', 'string', 'max:255'],
            'profiles.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.required' => 'Укажите название станции.',
            'awtcod.required' => 'Укажите код станции (awtcod) — по нему станцию знают BUSTER, сайт и отчёты.',
            'awtcod.unique' => 'Станция с таким кодом уже есть в справочнике.',
            'awtcod.max' => 'Код станции не может быть больше 32767 — в базе станции это поле SMALLINT.',
            'profiles.*.db_host.required' => 'Укажите адрес базы.',
            'profiles.*.db_name.required' => 'Укажите имя базы.',
            'profiles.*.label.required' => 'Назовите вариант подключения — по названию его и переключают.',
        ];
    }

    /** @return array<string, mixed> */
    private function stationAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'buh' => $data['buh'] ?? null,
            'buh_st' => $data['buh_st'] ?? null,
            'buh_ksr' => $data['buh_ksr'] ?? null,
            'stationkey' => $data['stationkey'] ?? null,
            'inlist' => (int) ($data['inlist'] ?? 0),
            'is_active' => (bool) $data['is_active'],
            'sales_enabled' => (bool) $data['sales_enabled'],
            'refunds_enabled' => (bool) $data['refunds_enabled'],
            'active_profile' => (int) $data['active_profile'],
        ];
    }

    /**
     * Профили станции: присланные слоты создаются или обновляются,
     * отсутствующие удаляются. Пустой пароль сохраняет прежний —
     * иначе правка адреса стирала бы пароль, которого форма не видит.
     *
     * @param  array<int, array>  $profiles
     */
    private function saveProfiles(Station $station, array $profiles): void
    {
        $slots = [];

        foreach ($profiles as $profile) {
            $slot = (int) $profile['slot'];
            $slots[] = $slot;

            $existing = StationProfile::where('station_awtcod', $station->awtcod)
                ->where('slot', $slot)
                ->first();

            $password = (string) ($profile['db_password'] ?? '');

            StationProfile::updateOrCreate(
                ['station_awtcod' => $station->awtcod, 'slot' => $slot],
                [
                    'label' => $profile['label'],
                    'db_host' => $profile['db_host'],
                    'db_port' => (int) $profile['db_port'],
                    'db_name' => $profile['db_name'],
                    'db_user' => $profile['db_user'],
                    'db_password' => $password !== '' ? $password : (string) ($existing->db_password ?? ''),
                    'notes' => $profile['notes'] ?? null,
                ],
            );
        }

        StationProfile::where('station_awtcod', $station->awtcod)
            ->when($slots !== [], fn ($q) => $q->whereNotIn('slot', $slots))
            ->delete();
    }
}
