<?php

namespace App\Models;

use App\Services\AisEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала: что сотрудник АИС сделал с кабинетом покупателя.
 *
 * Пишется ТОЛЬКО на действия, а не на просмотры: журнал нужен, чтобы
 * ответить «кто сменил адрес у этого кабинета» и «кто заходил в него от
 * имени покупателя», а от листания списка он бы просто распух.
 */
class CustomerAdminAction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    /** Сброс пароля: гасим и bcrypt, и legacy-хэш. */
    public const ACTION_RESET_PASSWORD = 'reset_password';

    /** Адрес подтверждён вручную (сотрудник говорил с покупателем). */
    public const ACTION_VERIFY_EMAIL = 'verify_email';

    /** Завершены все сессии кабинета (украденный или чужой телефон). */
    public const ACTION_LOGOUT_ALL = 'logout_all';

    public const ACTION_UNLINK_VK = 'unlink_vk';

    public const ACTION_CHANGE_EMAIL = 'change_email';

    /** Выдан код входа в кабинет от имени покупателя. */
    public const ACTION_IMPERSONATE = 'impersonate';

    /** Названия действий для карточки покупателя в АИС. */
    public const TITLES = [
        self::ACTION_RESET_PASSWORD => 'Сброшен пароль',
        self::ACTION_VERIFY_EMAIL => 'E-mail подтверждён вручную',
        self::ACTION_LOGOUT_ALL => 'Завершены все сессии',
        self::ACTION_UNLINK_VK => 'Отвязан вход через VK',
        self::ACTION_CHANGE_EMAIL => 'Изменён e-mail',
        self::ACTION_IMPERSONATE => 'Вход в кабинет от имени покупателя',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Записать действие. Логин и ФИО сотрудника копируются в журнал: его
     * могут переименовать или удалить, а запись должна остаться читаемой.
     */
    public static function record(
        AisEmployee $employee,
        string $action,
        ?User $user,
        ?string $email = null,
        array $details = [],
        ?string $ip = null,
    ): self {
        return self::create([
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'employee_id' => $employee->id,
            'employee_login' => $employee->login,
            'employee_name' => $employee->fullName,
            'action' => $action,
            'details' => $details ?: null,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    public function title(): string
    {
        return self::TITLES[$this->action] ?? $this->action;
    }
}
