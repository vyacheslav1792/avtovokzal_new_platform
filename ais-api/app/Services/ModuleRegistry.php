<?php

namespace App\Services;

use App\Reports\Report;

/**
 * Каталог модулей АИС и их прав — то, из чего редактор роли рисует галочки.
 *
 * Статическая часть (администрирование, содержимое сайта, возврат билета)
 * описана в shared/ais_permissions.php. Отчёты туда НЕ переписываем: их
 * список живёт в config/reports.php, и модуль каждого отчёта собирается на
 * лету как `report:<slug>` с набором прав `report_permissions` — иначе
 * список отчётов пришлось бы держать в двух местах.
 */
class ModuleRegistry
{
    /** @var array<string, array>|null */
    private ?array $modules = null;

    /**
     * Все модули: ключ => [title, group, permissions, confirm].
     *
     * @return array<string, array>
     */
    public function modules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $modules = [];

        // Отчёты идут первыми — в редакторе ролей их больше всего.
        $reportPermissions = config('ais_modules.report_permissions', ['view' => 'Просмотр отчёта']);
        foreach (config('reports', []) as $slug => $class) {
            $meta = app($class)->meta();
            $modules['report:'.$slug] = [
                'title' => $meta['title'] ?? $slug,
                'group' => 'Отчёты'.(($meta['group'] ?? '') !== '' ? ' · '.$meta['group'] : ''),
                'permissions' => $reportPermissions,
                'confirm' => [],
            ];
        }

        foreach (config('ais_modules.modules', []) as $key => $module) {
            $modules[$key] = [
                'title' => $module['title'] ?? $key,
                'group' => $module['group'] ?? '',
                'permissions' => $module['permissions'] ?? [],
                'confirm' => $module['confirm'] ?? [],
            ];
        }

        return $this->modules = $modules;
    }

    /** Все существующие права вида "<модуль>.<право>" — для валидации роли. */
    public function permissionKeys(): array
    {
        $keys = [];
        foreach ($this->modules() as $module => $meta) {
            foreach (array_keys($meta['permissions']) as $permission) {
                $keys[] = $module.'.'.$permission;
            }
        }

        return $keys;
    }

    /** Требует ли право кода подтверждения опасного действия. */
    public function needsConfirm(string $permission): bool
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, '');

        return in_array($action, $this->modules()[$module]['confirm'] ?? [], true);
    }

    /**
     * Каталог для редактора ролей: модули, сгруппированные для показа.
     *
     * @return array<int, array{group: string, modules: array}>
     */
    public function catalog(): array
    {
        $groups = [];
        foreach ($this->modules() as $key => $meta) {
            $group = $meta['group'] !== '' ? $meta['group'] : 'Прочее';
            $groups[$group][] = [
                'key' => $key,
                'title' => $meta['title'],
                'permissions' => array_map(
                    fn (string $permission, string $title) => [
                        'key' => $permission,
                        'title' => $title,
                        'confirm' => in_array($permission, $meta['confirm'], true),
                    ],
                    array_keys($meta['permissions']),
                    array_values($meta['permissions']),
                ),
            ];
        }

        return array_map(
            fn (string $group, array $modules) => ['group' => $group, 'modules' => $modules],
            array_keys($groups),
            array_values($groups),
        );
    }

    /** Модуль отчёта по его slug (ключ права на просмотр — `…view`). */
    public static function reportModule(string $slug): string
    {
        return 'report:'.$slug;
    }

    /** Экземпляр отчёта по slug (null, если такого отчёта нет). */
    public function report(string $slug): ?Report
    {
        $class = config('reports')[$slug] ?? null;

        return $class ? app($class) : null;
    }
}
