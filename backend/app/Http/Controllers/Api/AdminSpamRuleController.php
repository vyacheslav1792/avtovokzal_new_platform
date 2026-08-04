<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpamRule;
use App\Services\SpamFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Антиспам-словарь формы обратной связи из админки АИС.
 * В legacy это были preg_match прямо в url/contact.php: новое спам-слово =
 * правка исходника и выкладка на боевой сервер.
 *
 * БЕЗ авторизации — как остальные админские разделы (этап users).
 */
class AdminSpamRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'rules' => SpamRule::query()
                    ->orderBy('position')->orderBy('id')
                    ->get()
                    ->map(fn (SpamRule $r) => [
                        'id' => $r->id,
                        'kind' => $r->kind,
                        'scope' => $r->scope,
                        'pattern' => $r->pattern,
                        'note' => $r->note,
                        'is_active' => $r->is_active,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * Сохранение: список приходит целиком и в нужном порядке, содержимое
     * таблицы заменяется — как у контактов и меню.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.kind' => ['required', Rule::in(SpamRule::KINDS)],
            'rules.*.scope' => ['required', Rule::in(SpamRule::SCOPES)],
            'rules.*.pattern' => ['nullable', 'string', 'max:500'],
            'rules.*.note' => ['nullable', 'string', 'max:255'],
            'rules.*.is_active' => ['boolean'],
        ], [
            'rules.*.kind.in' => 'Неизвестный тип правила антиспама',
            'rules.*.scope.in' => 'Неизвестная область проверки',
        ]);

        foreach ($data['rules'] as $i => $rule) {
            // Правило-шаблон без паттерна ничего не проверяет; исключение —
            // служебное latin_dominates, ему паттерн не нужен.
            if ($rule['kind'] !== SpamRule::KIND_LATIN && trim((string) ($rule['pattern'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'В правиле №'.($i + 1).' не заполнен образец.',
                ], 422);
            }

            // Битое регулярное выражение молча пропускало бы весь спам.
            if ($rule['kind'] === SpamRule::KIND_REGEX
                && SpamFilter::compile((string) $rule['pattern']) === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'В правиле №'.($i + 1).' ошибка в регулярном выражении.',
                ], 422);
            }
        }

        DB::transaction(function () use ($data) {
            SpamRule::query()->delete();

            foreach (array_values($data['rules']) as $position => $rule) {
                SpamRule::create([
                    'position' => $position,
                    'kind' => $rule['kind'],
                    'scope' => $rule['scope'],
                    'pattern' => isset($rule['pattern']) ? trim((string) $rule['pattern']) : null,
                    'note' => isset($rule['note']) ? trim((string) $rule['note']) : null,
                    'is_active' => (bool) ($rule['is_active'] ?? true),
                ]);
            }
        });

        // Массовое удаление событий модели не вызывает — чистим кэш явно.
        SpamRule::forgetCache();

        return $this->index();
    }
}
