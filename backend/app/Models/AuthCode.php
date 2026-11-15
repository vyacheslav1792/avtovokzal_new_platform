<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Код подтверждения из письма (6 цифр): регистрация, восстановление
 * пароля и подтверждение e-mail, добавленного к кабинету без почты.
 *
 * В базе только bcrypt кода. Выдачу, проверку и лимиты держит
 * App\Services\AuthCodeService — модель про хранение.
 */
class AuthCode extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'blocked_at' => 'datetime',
    ];

    /** Регистрация: пользователя ещё нет, данные лежат в payload. */
    public const TYPE_REGISTER = 'register';

    /** Восстановление пароля. */
    public const TYPE_PASSWORD_RESET = 'password_reset';

    /** Подтверждение e-mail, добавляемого к кабинету (вход через VK). */
    public const TYPE_EMAIL_CONFIRM = 'email_confirm';

    /**
     * Сам код — только в памяти, сразу после выпуска: его нужно положить
     * в письмо. В базу уходит лишь хэш.
     */
    public ?string $plainCode = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Код, которым ещё можно воспользоваться. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->whereNull('blocked_at')
            ->where('expires_at', '>', now());
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
