<?php

namespace App\Services;

use App\Models\AuthCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Коды подтверждения из писем: выпуск, проверка и лимиты.
 *
 * Ограничения (config/site.php → auth_code) одни для всех трёх процессов —
 * регистрации, восстановления пароля и подтверждения адреса:
 *  - код живёт 30 минут;
 *  - пять попыток ввода на пару «адрес + код», после чего код гасится;
 *  - повторная отправка не чаще раза в 3 минуты;
 *  - не более пяти кодов на адрес в сутки (по каждому процессу отдельно).
 *
 * Счётчики лежат в таблице `auth_codes`, а не в сессии: требование —
 * считать попытки независимо от браузера, из которого их вводят.
 */
class AuthCodeService
{
    /**
     * Выпустить код и погасить прежний: активным остаётся только последний
     * присланный, иначе счётчик попыток был бы неоднозначным.
     *
     * @param  array<string, mixed>  $payload  данные незавершённой регистрации
     */
    public function issue(
        string $type,
        string $email,
        ?int $userId = null,
        array $payload = [],
        ?string $ip = null,
    ): AuthCode {
        $this->assertCanIssue($type, $email);

        AuthCode::query()
            ->where('type', $type)
            ->where('email', $email)
            ->whereNull('used_at')
            ->whereNull('blocked_at')
            ->update(['used_at' => now()]);

        $code = $this->generateCode();

        $record = AuthCode::create([
            'type' => $type,
            'email' => $email,
            'user_id' => $userId,
            'code_hash' => Hash::make($code),
            'payload' => $payload ?: null,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'ip' => $ip,
        ]);

        $record->plainCode = $code;

        return $record;
    }

    /**
     * Проверить код. Неверный расходует попытку; на последней код гасится
     * целиком — дальше нужен новый.
     *
     * Возвращает запись, НЕ помечая её использованной: вызывающий код
     * гасит её сам, уже внутри своей транзакции (создание пользователя,
     * смена пароля), чтобы код не «сгорал» при неудаче.
     */
    public function verify(string $type, string $email, string $code): AuthCode
    {
        $record = AuthCode::query()
            ->where('type', $type)
            ->where('email', $email)
            ->active()
            ->latest('created_at')
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'code' => 'Код недействителен или истёк. Запросите новый код.',
            ]);
        }

        if (Hash::check(trim($code), $record->code_hash)) {
            return $record;
        }

        $record->increment('attempts');
        $left = $this->maxAttempts() - $record->attempts;

        if ($left <= 0) {
            $record->update(['blocked_at' => now()]);

            throw ValidationException::withMessages([
                'code' => 'Код введён неверно '.$this->maxAttempts()
                    .' раз и больше не действует. Запросите новый код.',
            ]);
        }

        throw ValidationException::withMessages([
            'code' => 'Неверный код. '.$this->attemptsLeftText($left),
        ]);
    }

    /**
     * Состояние для формы ввода кода: когда можно переслать, сколько
     * осталось попыток и жизни у кода. Фронт по этим числам ведёт отсчёт,
     * а не держит свои константы.
     *
     * @return array{email: string, resend_after: int, expires_in: int, attempts_left: int, codes_left: int}
     */
    public function state(string $type, string $email): array
    {
        $last = AuthCode::query()
            ->where('type', $type)
            ->where('email', $email)
            ->latest('created_at')
            ->first();

        $active = $last && ! $last->used_at && ! $last->blocked_at && $last->expires_at->isFuture()
            ? $last
            : null;

        return [
            'email' => $email,
            'resend_after' => $last ? $this->secondsUntilResend($last) : 0,
            'expires_in' => $active ? max(0, (int) now()->diffInSeconds($active->expires_at, false)) : 0,
            'attempts_left' => $active ? max(0, $this->maxAttempts() - $active->attempts) : 0,
            'codes_left' => max(0, $this->maxPerDay() - $this->issuedToday($type, $email)),
        ];
    }

    /** Лимиты выпуска: пауза между письмами и суточный потолок. */
    private function assertCanIssue(string $type, string $email): void
    {
        $last = AuthCode::query()
            ->where('type', $type)
            ->where('email', $email)
            ->latest('created_at')
            ->first();

        if ($last && ($wait = $this->secondsUntilResend($last)) > 0) {
            throw ValidationException::withMessages([
                'code' => 'Новый код можно запросить через '.$this->secondsText($wait).'.',
            ]);
        }

        if ($this->issuedToday($type, $email) >= $this->maxPerDay()) {
            throw ValidationException::withMessages([
                'email' => 'На этот адрес отправлено '.$this->maxPerDay()
                    .' кодов за сутки — больше нельзя. '
                    .$this->nextDayText($type, $email)
                    .' Если письма не приходят, позвоните в справочную '
                    .config('site.support_phone').'.',
            ]);
        }
    }

    /** Сколько кодов этого типа ушло на адрес за последние сутки. */
    private function issuedToday(string $type, string $email): int
    {
        return AuthCode::query()
            ->where('type', $type)
            ->where('email', $email)
            ->where('created_at', '>', now()->subDay())
            ->count();
    }

    /** Когда суточный лимит отпустит: сутки от самого раннего из кодов. */
    private function nextDayText(string $type, string $email): string
    {
        $oldest = AuthCode::query()
            ->where('type', $type)
            ->where('email', $email)
            ->where('created_at', '>', now()->subDay())
            ->oldest('created_at')
            ->first();

        if (! $oldest) {
            return 'Попробуйте позже.';
        }

        return 'Попробуйте снова после '
            .$oldest->created_at->addDay()->format('H:i').'.';
    }

    private function secondsUntilResend(AuthCode $last): int
    {
        $allowed = $last->created_at->addSeconds($this->resendAfterSeconds());

        return max(0, (int) now()->diffInSeconds($allowed, false));
    }

    /**
     * Шестизначный код. `random_int` — криптостойкий источник: обычный
     * rand() у кода, которым открывают кабинет, недопустим.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('site.auth_code.ttl_minutes'));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('site.auth_code.max_attempts'));
    }

    private function resendAfterSeconds(): int
    {
        return max(0, (int) config('site.auth_code.resend_after_seconds'));
    }

    private function maxPerDay(): int
    {
        return max(1, (int) config('site.auth_code.max_per_day'));
    }

    /** «Осталась 1 попытка» / «Осталось 2 попытки» / «Осталось 5 попыток». */
    private function attemptsLeftText(int $left): string
    {
        $word = match (true) {
            $left % 10 === 1 && $left % 100 !== 11 => 'попытка',
            in_array($left % 10, [2, 3, 4], true) && ! in_array($left % 100, [12, 13, 14], true) => 'попытки',
            default => 'попыток',
        };

        $verb = $word === 'попытка' ? 'Осталась' : 'Осталось';

        return "{$verb} {$left} {$word}.";
    }

    /** «через 2 мин 30 с» / «через 45 с» — для паузы между письмами. */
    private function secondsText(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} с";
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $rest > 0 ? "{$minutes} мин {$rest} с" : "{$minutes} мин";
    }

    /** Время окончания жизни кода — для текста письма. */
    public function expiresAtText(AuthCode $code): string
    {
        return Carbon::parse($code->expires_at)
            ->timezone(config('app.timezone'))
            ->format('H:i');
    }
}
