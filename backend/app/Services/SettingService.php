<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Настройки сайта, которые сотрудник меняет из АИС: продажа и возврат
 * билетов. В legacy выключателя не было — при неполадках у станции
 * покупатель оплачивал билет, который потом не оформлялся, и деньги
 * приходилось возвращать вручную.
 *
 * Значение хранится в БД как JSON, список настроек — config/settings.php.
 * Читается на каждом запросе оформления, поэтому кэшируется одной записью.
 */
class SettingService
{
    private const CACHE = 'settings:all';

    private const CACHE_TTL = 300;

    /**
     * Все настройки: ключ → значение, с подстановкой значений по умолчанию
     * из каталога (настройку могли ни разу не менять).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $saved = Cache::remember(self::CACHE, self::CACHE_TTL, function () {
            // В кэш кладём массив: Eloquent Collection в database-кэше
            // десериализуется в incomplete class.
            return Setting::query()->pluck('value', 'key')->all();
        });

        $values = [];

        foreach (self::catalog() as $key => $meta) {
            $values[$key] = array_key_exists($key, $saved)
                ? self::cast($saved[$key], $meta)
                : $meta['default'];
        }

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** Продажа билетов на сайте включена. */
    public function salesEnabled(): bool
    {
        return (bool) $this->get('sales_enabled');
    }

    /** Возврат билетов на сайте включён (возврата через АИС не касается). */
    public function refundsEnabled(): bool
    {
        return (bool) $this->get('refunds_enabled');
    }

    /**
     * Настройки для сайта — только помеченные `public`: служебные значения
     * фронту знать незачем.
     *
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $all = $this->all();

        return array_intersect_key(
            $all,
            array_filter(self::catalog(), fn (array $meta) => $meta['public'] ?? false),
        );
    }

    /**
     * Каталог с текущими значениями для админки.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminSettings(): array
    {
        $values = $this->all();
        $settings = [];

        foreach (self::catalog() as $key => $meta) {
            $settings[] = [
                'key' => $key,
                'title' => $meta['title'],
                'hint' => $meta['hint'] ?? '',
                'type' => $meta['type'] ?? 'bool',
                'value' => $values[$key],
                'default' => $meta['default'],
            ];
        }

        return $settings;
    }

    /**
     * Сохранение из админки: присылается список настроек целиком, как у
     * меню и блоков. Незнакомые ключи игнорируются.
     *
     * @param  array<int, array<string, mixed>>  $settings
     */
    public function replace(array $settings): array
    {
        $catalog = self::catalog();

        DB::transaction(function () use ($settings, $catalog) {
            foreach ($settings as $item) {
                $key = (string) ($item['key'] ?? '');

                if (! isset($catalog[$key]) || ! array_key_exists('value', $item)) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => self::cast($item['value'], $catalog[$key])],
                );
            }
        });

        self::forgetCache();

        return $this->adminSettings();
    }

    /** @return array<string, array<string, mixed>> */
    private static function catalog(): array
    {
        return config('settings', []);
    }

    private static function cast(mixed $value, array $meta): mixed
    {
        return ($meta['type'] ?? 'bool') === 'bool' ? (bool) $value : $value;
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE);
    }
}
