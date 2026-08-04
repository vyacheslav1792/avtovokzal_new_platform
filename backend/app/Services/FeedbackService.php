<?php

namespace App\Services;

use App\Jobs\SendFeedbackNotification;
use App\Models\FeedbackMessage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Обратная связь сайта (перенос legacy avtovokzal57/url/contact.php).
 *
 * В legacy обработчик сразу слал письмо на support_email и ничего не хранил:
 * потерянное письмо = потерянное обращение, а сработавший антиспам-словарь
 * выбрасывал сообщение молча. Здесь любое обращение сохраняется, спам лишь
 * помечается, а письмо — уведомление о новом обращении.
 */
class FeedbackService
{
    public function __construct(
        private readonly SpamFilter $spam,
        private readonly SmartCaptcha $captcha,
    ) {}

    /**
     * Приём обращения с сайта.
     *
     * @param  array{topic?: ?string, name: string, email: string, phone?: ?string, body: string}  $data
     * @param  array{ip?: ?string, user_agent?: ?string, user_id?: ?int, captcha_token?: ?string}  $context
     */
    public function store(array $data, array $context = []): FeedbackMessage
    {
        $body = trim($data['body']);
        $name = trim($data['name']);
        $email = mb_strtolower(trim($data['email']));

        $spamReason = $this->spam->match([
            'name' => $name,
            'email' => $email,
            'body' => $body,
        ]);

        $message = FeedbackMessage::create([
            'topic' => $data['topic'] ?? null,
            'name' => $name,
            'email' => $email,
            'phone' => isset($data['phone']) ? trim((string) $data['phone']) : null,
            'body' => $body,
            'status' => $spamReason === null
                ? FeedbackMessage::STATUS_NEW
                : FeedbackMessage::STATUS_SPAM,
            'spam_reason' => $spamReason,
            'user_id' => $context['user_id'] ?? null,
            'ip' => $context['ip'] ?? null,
            'user_agent' => mb_substr((string) ($context['user_agent'] ?? ''), 0, 500) ?: null,
            // При выключенной капче отметки нет — видно, что проверки не было.
            'captcha_passed' => $this->captcha->enabled() ? true : null,
        ]);

        // Спам не тревожит службу поддержки — он лежит в АИС, и оператор
        // может вернуть его в работу, если словарь ошибся.
        if (! $message->isSpam()) {
            SendFeedbackNotification::dispatch($message->id)->onQueue('emails');
        } else {
            Log::info('Обращение помечено как спам', [
                'id' => $message->id,
                'reason' => $spamReason,
            ]);
        }

        return $message;
    }

    /**
     * Метка времени открытия формы: подписанная, поэтому подделать её нельзя.
     * По ней видно, что форму заполнил человек, а не бот, отправивший её
     * мгновенно после загрузки.
     */
    public function issueFormToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Похоже ли, что форму заполнял человек. Токен необязателен: если фронт
     * его не прислал (старая вкладка, отключённый JS) — не отказываем,
     * остальные проверки никуда не делись.
     */
    public function formTokenIsSane(?string $token): bool
    {
        $token = trim((string) $token);

        if ($token === '') {
            return true;
        }

        try {
            $issued = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return false;
        }

        $elapsed = now()->getTimestamp() - $issued;

        return $elapsed >= (int) config('feedback.min_fill_seconds')
            && $elapsed <= (int) config('feedback.form_ttl_minutes') * 60;
    }

    /** Данные для формы сайта: темы, признак капчи и метка времени. */
    public function formMeta(): array
    {
        return [
            'topics' => collect(config('feedback.topics'))
                ->map(fn (string $title, string $key) => ['value' => $key, 'label' => $title])
                ->values()
                ->all(),
            'captcha_enabled' => $this->captcha->enabled(),
            'form_token' => $this->issueFormToken(),
            'max_body_length' => (int) config('feedback.max_body_length'),
        ];
    }
}
