<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Правило антиспама для формы обратной связи (перенос словаря legacy —
 * ~60 preg_match прямо в url/contact.php). Правится в админке АИС.
 *
 * Список читается на каждой отправке формы, поэтому держим его в кэше;
 * кэш сбрасывается при любом изменении таблицы.
 */
class SpamRule extends Model
{
    /** Подстрока без учёта регистра — обычное слово словаря. */
    public const KIND_SUBSTRING = 'substring';

    /** Регулярное выражение — ссылки и шаблоны вида «флакон.*». */
    public const KIND_REGEX = 'regex';

    /** Латиницы в тексте больше, чем кириллицы (правило legacy). */
    public const KIND_LATIN = 'latin_dominates';

    public const KINDS = [self::KIND_SUBSTRING, self::KIND_REGEX, self::KIND_LATIN];

    public const SCOPES = ['body', 'name', 'email', 'any'];

    protected $guarded = [];

    private const CACHE_KEY = 'spam_rules:active';

    private const CACHE_TTL = 300;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }

    /**
     * Действующие правила. В кэш кладём обычные массивы: Eloquent Collection
     * в database-кэше десериализуется в incomplete class.
     *
     * @return array<int, array{kind: string, scope: string, pattern: ?string, note: ?string}>
     */
    public static function active(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => static::query()
            ->where('is_active', true)
            ->orderBy('position')->orderBy('id')
            ->get(['kind', 'scope', 'pattern', 'note'])
            ->map(fn (self $rule) => [
                'kind' => $rule->kind,
                'scope' => $rule->scope,
                'pattern' => $rule->pattern,
                'note' => $rule->note,
            ])
            ->all());
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
