<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedbackMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Разбор обращений с сайта в админке АИС (ais-spa, «Обращения с сайта»).
 * В legacy обращения нигде не хранились — были только письма на почте
 * поддержки, и что с ними стало, никто не видел.
 *
 * БЕЗ авторизации — как контакты, страницы и админский возврат;
 * на прод не открывать (авторизация появится на этапе users).
 */
class AdminFeedbackController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Список обращений: фильтры по статусу, тексту и датам, страницами.
     * По умолчанию спам не показываем — для него отдельный фильтр.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...FeedbackMessage::STATUSES, 'all', 'active'])],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $data['status'] ?? 'active';

        $query = FeedbackMessage::query()->orderByDesc('created_at');

        if ($status === 'active') {
            $query->where('status', '!=', FeedbackMessage::STATUS_SPAM);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($data['q'])) {
            $like = '%'.mb_strtolower(trim($data['q'])).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(body) like ?', [$like])
                    ->orWhere('phone', 'like', $like);
            });
        }

        // Даты — московские сутки целиком (app.timezone = Europe/Moscow).
        if (! empty($data['date_from'])) {
            $query->where('created_at', '>=', $data['date_from'].' 00:00:00');
        }

        if (! empty($data['date_to'])) {
            $query->where('created_at', '<=', $data['date_to'].' 23:59:59');
        }

        $page = $query->paginate(self::PER_PAGE, page: (int) ($data['page'] ?? 1));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => collect($page->items())->map(fn (FeedbackMessage $m) => $this->row($m))->all(),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'pages' => $page->lastPage(),
                'counts' => $this->counts(),
                'topics' => config('feedback.topics'),
            ],
        ]);
    }

    public function show(FeedbackMessage $feedback): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->row($feedback, full: true)]);
    }

    /** Смена статуса и заметка оператора. */
    public function update(Request $request, FeedbackMessage $feedback): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(FeedbackMessage::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $feedback->status = $data['status'];
            // Отметка времени ответа проставляется один раз — по ней в
            // отчётах видно скорость реакции службы поддержки.
            $feedback->answered_at = $data['status'] === FeedbackMessage::STATUS_ANSWERED
                ? ($feedback->answered_at ?? now())
                : null;
        }

        if (array_key_exists('admin_note', $data)) {
            $feedback->admin_note = $data['admin_note'];
        }

        $feedback->save();

        return response()->json(['success' => true, 'data' => $this->row($feedback, full: true)]);
    }

    public function destroy(FeedbackMessage $feedback): JsonResponse
    {
        $feedback->delete();

        return response()->json(['success' => true]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = FeedbackMessage::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $result = ['all' => array_sum($counts)];

        foreach (FeedbackMessage::STATUSES as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        $result['active'] = $result['all'] - $result[FeedbackMessage::STATUS_SPAM];

        return $result;
    }

    private function row(FeedbackMessage $m, bool $full = false): array
    {
        $row = [
            'id' => $m->id,
            'topic' => $m->topic,
            'topic_title' => $m->topic ? (config('feedback.topics')[$m->topic] ?? $m->topic) : null,
            'name' => $m->name,
            'email' => $m->email,
            'phone' => $m->phone,
            'body' => $m->body,
            'status' => $m->status,
            'spam_reason' => $m->spam_reason,
            'admin_note' => $m->admin_note,
            'user_id' => $m->user_id,
            'created_at' => $m->created_at?->format('d.m.Y H:i'),
            'answered_at' => $m->answered_at?->format('d.m.Y H:i'),
        ];

        if ($full) {
            $row['ip'] = $m->ip;
            $row['user_agent'] = $m->user_agent;
            $row['captcha_passed'] = $m->captcha_passed;
        }

        return $row;
    }
}
