<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Адрес в чёрном списке (перенос legacy $CHS): ему закрыты покупка,
 * регистрация, восстановление пароля и приглашение в кабинет.
 *
 * Список читается на каждой покупке, поэтому держим его в кэше;
 * кэш сбрасывается при любом изменении таблицы.
 */
class BlockedEmail extends Model
{
    protected $guarded = [];

    private const CACHE_KEY = 'blocked_emails';

    private const CACHE_TTL = 300;

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** Заблокирован ли адрес (регистр и пробелы не важны). */
    public static function blocked(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && in_array($email, static::all_(), true);
    }

    /**
     * Весь список адресов. В кэш кладём обычный массив: Eloquent Collection
     * в database-кэше десериализуется в incomplete class.
     *
     * @return string[]
     */
    public static function all_(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => static::query()->pluck('email')->all(),
        );
    }

    /** Добавить адрес в список (идемпотентно). */
    public static function block(string $email, ?string $reason = null): self
    {
        return static::updateOrCreate(
            ['email' => mb_strtolower(trim($email))],
            ['reason' => $reason],
        );
    }

    /** Убрать адрес из списка. Возвращает true, если он там был. */
    public static function unblock(string $email): bool
    {
        $model = static::where('email', mb_strtolower(trim($email)))->first();

        return $model ? (bool) $model->delete() : false;
    }
}
