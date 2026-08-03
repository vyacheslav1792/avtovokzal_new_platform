<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Одноразовая ссылка из письма: приглашение зарегистрироваться после
 * первой покупки и сброс пароля. В базе хранится только sha256 токена.
 */
class AuthToken extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public const TYPE_INVITE = 'invite';

    public const TYPE_PASSWORD_RESET = 'password_reset';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Выпустить токен: старые неиспользованные того же типа гасим, чтобы
     * работала только последняя присланная ссылка.
     *
     * @return string открытый токен для письма (в базе — только его хеш)
     */
    public static function issue(string $type, string $email, ?int $userId, int $ttlHours, ?string $ip = null): string
    {
        static::query()
            ->where('type', $type)
            ->where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(64);

        static::create([
            'type' => $type,
            'email' => $email,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours($ttlHours),
            'ip' => $ip,
        ]);

        return $token;
    }

    /** Найти живой (не использованный, не просроченный) токен. */
    public static function findValid(string $type, string $token): ?self
    {
        return static::query()
            ->where('type', $type)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
