<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Сессия сотрудника в АИС: токен, который SPA держит у себя и присылает
 * как Bearer. В базе только sha256 — сам токен виден один раз, при входе.
 *
 * Эту же таблицу читает backend, когда проверяет сотрудника на своих
 * /api/v1/admin/* (хэш считается тем же sha256, без секрета приложения, —
 * иначе пришлось бы делить APP_KEY между приложениями).
 */
class EmployeeToken extends Model
{
    protected $fillable = [
        'employee_id', 'token_hash', 'kind', 'ip', 'user_agent',
        // Работа от имени сотрудника: кем выдана сессия и до какого
        // времени действует (см. EmployeeImpersonation).
        'impersonated_by', 'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Сессия, выданная администратором «от имени» этого сотрудника. */
    public const KIND_IMPERSONATE = 'impersonate';

    public function isImpersonated(): bool
    {
        return $this->impersonated_by !== null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Завести сессию. Возвращает [модель, сам токен] — токен показывается
     * вызывающему один раз, в базу уходит только его хэш.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Employee $employee, string $kind, string $ip, string $userAgent): array
    {
        $token = Str::random(60);

        $model = static::create([
            'employee_id' => $employee->id,
            'token_hash' => static::hash($token),
            'kind' => $kind,
            'ip' => $ip,
            'user_agent' => Str::limit($userAgent, 250, ''),
        ]);

        $model->forceFill(['last_used_at' => now()])->save();

        return [$model, $token];
    }

    /**
     * Живая ли сессия: не превышен ни простой, ни предельный срок.
     * Срок один на оба приложения — shared/ais_auth.php.
     */
    public function isAlive(): bool
    {
        /*
         * Жёсткий срок — у сессий «от имени сотрудника»: он считается от
         * выдачи, а не от активности, поэтому проверяется раньше всего
         * (работа в чужой учётной записи не должна продлеваться сама собой).
         */
        if ($this->expires_at !== null && $this->expires_at->lt(now())) {
            return false;
        }

        $idle = (int) config('ais_auth.session_idle_minutes');
        $max = (int) config('ais_auth.session_max_minutes');
        $lastUsed = $this->last_used_at ?? $this->created_at;

        if ($idle > 0 && $lastUsed !== null && $lastUsed->lt(now()->subMinutes($idle))) {
            return false;
        }

        return ! ($max > 0 && $this->created_at !== null && $this->created_at->lt(now()->subMinutes($max)));
    }

    /** Отметить обращение. Чаще раза в минуту базу этим не трогаем. */
    public function touchUsage(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
