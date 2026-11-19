<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Reports\LgotReport;
use App\Services\LgotExclusions;
use App\Support\LgotKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * То, что отчёту «Нулевые билеты» нужно сверх запуска: список АТП за
 * период и два списка исключений (АТП по договорам и отдельные билеты).
 *
 * Списки СВОИ у каждого вида рейса (`kind`: пригородные,
 * внутриобластные, межобластные) — в legacy это были разные файлы у трёх
 * разных страниц.
 *
 * Почему это НЕ внутри `reports/{slug}/run`: запуск отчёта обязан быть
 * read-only. Он разрешён и в режиме «работаю от имени сотрудника»
 * (middleware employee.not-impersonated пропускает reports/*​/run), и
 * спрятать за ним правку списков значило бы править их из наблюдательного
 * режима. В legacy всё летело одним POST'ом в lgot_prig_api.php, потому
 * что там никакой авторизации не было вовсе.
 */
class LgotController extends Controller
{
    public function __construct(
        private readonly LgotReport $report,
        private readonly LgotExclusions $exclusions,
    ) {}

    /**
     * АТП, по которым за период есть нулевые билеты — отдельным запросом,
     * без выборки самих билетов. Этим снято требование legacy «сначала
     * расчёт по всем АТП для обновления списка АТП».
     */
    public function atps(Request $request): JsonResponse
    {
        return response()->json(['ok' => true] + $this->report->atpList($request->all()));
    }

    /** Оба списка исключений выбранного вида рейса. */
    public function exclusions(Request $request): JsonResponse
    {
        $kind = $this->kind($request);

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'list_atp' => $this->exclusions->atpListText($kind),
            'list_tickets' => $this->exclusions->tickets($kind),
        ]);
    }

    /**
     * Вид рейса запроса. Неизвестный вид — не ошибка: приводим к
     * пригородным (отчёт по ним открывается по умолчанию).
     */
    private function kind(Request $request): string
    {
        return LgotKind::normalize($request->input('kind'));
    }

    /** Сохранить список АТП по договорам целиком (строка на АТП). */
    public function saveAtps(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'max:8'],
            'list_atp' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        $kind = $this->kind($request);
        $names = $this->exclusions->saveAtpList($kind, (string) ($data['list_atp'] ?? ''));

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'saved' => count($names),
            'list_atp' => implode("\n", $names),
        ]);
    }

    /**
     * Добавить отмеченные билеты в список исключаемых. Присылается строка
     * отчёта целиком — список исключённых показывает те же колонки, а
     * билет мог уехать из выбранного периода, и перезапросить его нечем.
     */
    public function addTickets(Request $request): JsonResponse
    {
        $request->validate([
            'kind' => ['nullable', 'string', 'max:8'],
            'tickets' => ['required', 'array', 'min:1', 'max:5000'],
            'tickets.*' => ['array'],
            'tickets.*.tick_key' => ['required', 'string', 'max:96'],
        ], [
            'tickets.required' => 'Не отмечено ни одного билета.',
            'tickets.*.array' => 'Билет передан не строкой отчёта.',
            'tickets.*.tick_key.required' => 'У билета нет серии и номера — исключить его нечем.',
        ]);

        /*
         * ЛОВУШКА: строки берём из input, а НЕ из validated(). Правило
         * `tickets.*.tick_key` оставило бы в выдаче validated() один
         * tick_key, и в снимке билета (его показывает список исключённых
         * теми же колонками, что и отчёт) не осталось бы ничего.
         */
        /** @var Employee $employee */
        $employee = $request->user();
        $kind = $this->kind($request);
        $added = $this->exclusions->addTickets($kind, $request->input('tickets', []), $employee->login);

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'added' => $added,
            'list_tickets' => $this->exclusions->tickets($kind),
        ]);
    }

    /**
     * Убрать билеты из списка исключаемых: перечислением ключей либо
     * весь список сразу (кнопка «Очистить список исключений»).
     */
    public function removeTickets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'max:8'],
            'clear' => ['nullable', 'boolean'],
            'keys' => ['nullable', 'array', 'max:5000'],
            'keys.*' => ['string', 'max:96'],
        ]);

        $kind = $this->kind($request);
        $removed = ($data['clear'] ?? false)
            ? $this->exclusions->clearTickets($kind)
            : $this->exclusions->removeTickets($kind, $data['keys'] ?? []);

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'removed' => $removed,
            'list_tickets' => $this->exclusions->tickets($kind),
        ]);
    }
}
