<?php

namespace App\Services;

use App\Models\ContactDetail;
use App\Models\ContactPoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Контакты сайта (перенос legacy url/contacts.php): реквизиты организации
 * и список остановочных пунктов. В legacy правились в исходнике страницы,
 * теперь — в админке АИС (ais-spa, /contacts).
 */
class ContactService
{
    private const CACHE_KEY = 'contacts:public';

    private const CACHE_TTL = 300;

    /**
     * Данные для сайта: только видимые строки, без служебных полей.
     * Читаются на каждом заходе на страницу — держим в кэше, он сбрасывается
     * при любом сохранении контактов.
     */
    public function publicData(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $details = ContactDetail::query()
                ->where('is_active', true)
                ->orderBy('position')->orderBy('id')
                ->get()
                ->map(fn (ContactDetail $d) => [
                    'label' => $d->label,
                    'kind' => $d->kind,
                    'items' => $d->items ?? [],
                ]);

            $points = ContactPoint::query()
                ->where('is_active', true)
                ->orderBy('position')->orderBy('id')
                ->get()
                ->map(fn (ContactPoint $p) => [
                    'name' => $p->name,
                    'work_hours' => $p->work_hours ?? [],
                    'phones' => $p->phones ?? [],
                    'address' => $p->address,
                    'breaks' => $p->breaks ?? [],
                ]);

            // В кэш кладём массивы: Eloquent Collection в database-кэше
            // десериализуется в incomplete class.
            return [
                'details' => $details->all(),
                'points' => $points->all(),
            ];
        });
    }

    /** Данные для админки: все строки, включая скрытые. */
    public function adminData(): array
    {
        $details = ContactDetail::query()
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (ContactDetail $d) => [
                'id' => $d->id,
                'label' => $d->label,
                'kind' => $d->kind,
                'items' => $d->items ?? [],
                'is_active' => $d->is_active,
            ]);

        $points = ContactPoint::query()
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (ContactPoint $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'work_hours' => $p->work_hours ?? [],
                'phones' => $p->phones ?? [],
                'address' => $p->address,
                'breaks' => $p->breaks ?? [],
                'is_active' => $p->is_active,
            ]);

        return [
            'details' => $details->all(),
            'points' => $points->all(),
        ];
    }

    /**
     * Сохранение из админки: страница присылает оба списка целиком в нужном
     * порядке, мы заменяем содержимое таблиц. Порядок строк = порядок в
     * массиве, id клиента не значимы (строку могли добавить или удалить).
     */
    public function replace(array $details, array $points): array
    {
        DB::transaction(function () use ($details, $points) {
            ContactDetail::query()->delete();
            ContactPoint::query()->delete();

            foreach (array_values($details) as $position => $detail) {
                ContactDetail::create([
                    'position' => $position,
                    'label' => trim($detail['label']),
                    'kind' => $detail['kind'] ?? 'text',
                    'items' => self::cleanList($detail['items'] ?? []),
                    'is_active' => (bool) ($detail['is_active'] ?? true),
                ]);
            }

            foreach (array_values($points) as $position => $point) {
                ContactPoint::create([
                    'position' => $position,
                    'name' => trim($point['name']),
                    'work_hours' => self::cleanList($point['work_hours'] ?? []),
                    'phones' => self::cleanList($point['phones'] ?? []),
                    'address' => isset($point['address']) ? trim((string) $point['address']) : null,
                    'breaks' => self::cleanList($point['breaks'] ?? []),
                    'is_active' => (bool) ($point['is_active'] ?? true),
                ]);
            }
        });

        // Массовое удаление событий модели не вызывает — чистим кэш явно.
        self::forgetCache();

        return $this->adminData();
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Список строк без пустых значений и лишних пробелов: в админке строки
     * вводятся в textarea, пустая строка в конце — обычное дело.
     *
     * @param  array<int, string|null>  $list
     * @return array<int, string>
     */
    private static function cleanList(array $list): array
    {
        return array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), $list),
            fn (string $v) => $v !== '',
        ));
    }
}
