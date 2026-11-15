<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KkmServer;
use App\Services\ConnectionTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Настройки KkmServer (фискальные чеки) — раньше только файл
 * `kkm-api/config/kkm.php` и переменные окружения, то есть правка файла
 * и пересоздание контейнера.
 *
 * Вариантов настройки может быть несколько (боевая ККТ, эмулятор,
 * резервная касса), действует один — у него `is_active`. Ни одного
 * активного — фискализация выключена, и kkm-api отвечает отказом, а не
 * пробивает чек наугад.
 */
class KkmServerController extends Controller
{
    public function __construct(private readonly ConnectionTester $tester) {}

    public function index(): JsonResponse
    {
        $servers = KkmServer::orderBy('slot')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'servers' => $servers->map(fn (KkmServer $s) => $this->present($s))->all(),
                'slots' => KkmServer::SLOTS,
                // Пока справочник пуст, kkm-api работает по своему
                // config/kkm.php — админке стоит сказать об этом прямо.
                'from_file' => $servers->isEmpty(),
            ],
        ]);
    }

    /** Сохранение всего списка целиком — как у меню и блоков сайта. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'servers' => ['array', 'max:'.KkmServer::SLOTS],
            'servers.*.slot' => ['required', 'integer', 'min:1', 'max:'.KkmServer::SLOTS],
            'servers.*.label' => ['required', 'string', 'max:64'],
            'servers.*.url' => ['required', 'string', 'max:255'],
            'servers.*.user' => ['nullable', 'string', 'max:64'],
            // Пустой пароль = «оставить прежний»: наружу он не отдаётся.
            'servers.*.password' => ['nullable', 'string', 'max:255'],
            'servers.*.num_device' => ['required', 'integer', 'min:0', 'max:255'],
            'servers.*.command_timeout' => ['required', 'integer', 'min:1', 'max:600'],
            'servers.*.cashier_name' => ['nullable', 'string', 'max:128'],
            'servers.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'servers.*.url.required' => 'Укажите адрес KkmServer, например http://127.0.0.1:5893',
            'servers.*.label.required' => 'Назовите вариант настройки — по названию его и переключают.',
        ]);

        $slots = [];

        DB::transaction(function () use ($data, &$slots) {
            foreach ($data['servers'] ?? [] as $row) {
                $slot = (int) $row['slot'];
                $slots[] = $slot;
                $existing = KkmServer::where('slot', $slot)->first();
                $password = (string) ($row['password'] ?? '');

                KkmServer::updateOrCreate(['slot' => $slot], [
                    'label' => $row['label'],
                    'url' => rtrim($row['url'], '/'),
                    'user' => $row['user'] ?? null,
                    'password' => $password !== '' ? $password : ($existing->password ?? null),
                    'num_device' => (int) $row['num_device'],
                    'command_timeout' => (int) $row['command_timeout'],
                    'cashier_name' => $row['cashier_name'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    // Активность переключается отдельной ручкой: сохранение
                    // настроек не должно случайно включить другую ККТ.
                    'is_active' => (bool) ($existing->is_active ?? false),
                ]);
            }

            KkmServer::when($slots !== [], fn ($q) => $q->whereNotIn('slot', $slots))->delete();
        });

        $this->forgetKkmCache();

        return response()->json(['success' => true, 'data' => ['servers' => $this->all()]]);
    }

    /**
     * Переключение активного варианта. `slot = null` выключает
     * фискализацию целиком — чеки перестают пробиваться.
     */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slot' => ['nullable', 'integer', 'min:1', 'max:'.KkmServer::SLOTS],
        ]);

        $slot = $data['slot'] ?? null;

        if ($slot !== null && ! KkmServer::where('slot', $slot)->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'Такой вариант настройки не задан.',
            ], 422);
        }

        DB::transaction(function () use ($slot) {
            KkmServer::query()->update(['is_active' => false]);

            if ($slot !== null) {
                KkmServer::where('slot', $slot)->update(['is_active' => true]);
            }
        });

        $this->forgetKkmCache();

        return response()->json(['success' => true, 'data' => ['servers' => $this->all()]]);
    }

    /** Проверка связи: спрашиваем у ККТ её состояние (GetDataKKT). */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slot' => ['required', 'integer', 'min:1', 'max:'.KkmServer::SLOTS],
        ]);

        $server = KkmServer::where('slot', (int) $data['slot'])->firstOrFail();
        $result = $this->tester->kkm($server);

        return response()->json([
            'success' => true,
            'data' => ['result' => $result, 'servers' => $this->all()],
        ]);
    }

    /** @return array<int, array> */
    private function all(): array
    {
        return KkmServer::orderBy('slot')->get()->map(fn (KkmServer $s) => $this->present($s))->all();
    }

    /** @return array<string, mixed> */
    private function present(KkmServer $server): array
    {
        return [
            'slot' => $server->slot,
            'label' => $server->label,
            'is_active' => $server->is_active,
            'url' => $server->url,
            'user' => $server->user,
            'has_password' => (string) $server->password !== '',
            'num_device' => $server->num_device,
            'command_timeout' => $server->command_timeout,
            'cashier_name' => $server->cashier_name,
            'notes' => $server->notes,
            'checked_at' => $server->checked_at?->format('d.m.Y H:i'),
            'check_ok' => $server->check_ok,
            'check_error' => $server->check_error,
            'check_info' => $server->check_info,
        ];
    }

    /**
     * Свой кэш сбрасываем сразу; у kkm-api он собственный и живёт
     * 30 секунд — дольше ждать переключения не придётся.
     */
    private function forgetKkmCache(): void
    {
        Cache::forget('kkm:active-server');
    }
}
