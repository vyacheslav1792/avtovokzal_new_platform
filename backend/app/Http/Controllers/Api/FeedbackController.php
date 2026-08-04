<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackRequest;
use App\Services\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Форма обратной связи сайта (перенос legacy avtovokzal57/url/contact.php,
 * модалка «Написать сообщение»).
 */
class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedback) {}

    /** Данные для формы: темы обращения, признак капчи, метка времени. */
    public function form(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->feedback->formMeta(),
        ]);
    }

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        // Квоту тратят только принятые сообщения: посетитель, у которого
        // форма несколько раз не прошла валидацию, не должен оставаться без
        // связи на час. От флуда как такового защищает middleware throttle.
        $key = 'feedback:'.$request->ip();
        $limit = (int) config('feedback.rate_limit_per_hour');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'success' => false,
                'message' => 'Вы отправили слишком много сообщений подряд. '
                    .'Попробуйте позже или позвоните в справочную: 8 (4862) 72-11-11.',
            ], 429);
        }

        $message = $this->feedback->store(
            $request->safe()->only(['topic', 'name', 'email', 'phone', 'body']),
            [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $request->user()?->id,
            ],
        );

        RateLimiter::hit($key, 3600);

        // Про пометку спамом отправителю не сообщаем: словарь мог ошибиться,
        // и оператор всё равно увидит обращение в АИС.
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $message->id,
                'message' => 'Сообщение отправлено. Обращения обрабатываются '
                    .'в рабочие дни с 8 до 17 часов — ответ придёт на указанный e-mail.',
            ],
        ], 201);
    }
}
