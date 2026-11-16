<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Проверка сотрудника АИС на админских маршрутах сайта.
 *
 * Сотрудники, роли и сессии живут в БД `ais` — их заводит и правит ais-api
 * (там же вход, профиль и редактор ролей). Backend читает те же таблицы
 * напрямую (соединение `ais`): админку АИС и backend открывают с ОДНОГО
 * домена (`/` — SPA, `/api/` — ais-api, `/site-api/` — backend), поэтому
 * httpOnly-cookie сессии приходит и сюда, а спрашивать права по сети у
 * ais-api на каждый запрос незачем.
 *
 * Пишем в эту базу единственное — отметку обращения к сессии: без неё
 * сессия, которой пользуются только в разделах сайта (страницы, меню,
 * обращения), «простаивала» бы и истекла.
 */
class AisAuth
{
    private ?AisEmployee $resolved = null;

    private bool $resolvedOnce = false;

    public function employee(Request $request): ?AisEmployee
    {
        if ($this->resolvedOnce) {
            return $this->resolved;
        }

        $this->resolvedOnce = true;

        return $this->resolved = $this->lookup($request);
    }

    private function lookup(Request $request): ?AisEmployee
    {
        $token = $this->tokenFromRequest($request);

        if ($token === null) {
            return null;
        }

        // Хэш — тот же sha256 без секрета, что считает ais-api
        // (App\Models\EmployeeToken::hash): иначе приложениям пришлось бы
        // делить APP_KEY.
        $hash = hash('sha256', $token);

        $session = DB::connection('ais')->table('employee_tokens')
            ->where('token_hash', $hash)
            ->first();

        if (! $session || ! $this->sessionAlive($session)) {
            return null;
        }

        $row = DB::connection('ais')->table('employees')
            ->where('id', $session->employee_id)
            ->where('is_active', true)
            ->first();

        if (! $row) {
            return null;
        }

        $this->touchSession($session);

        return new AisEmployee(
            id: (int) $row->id,
            login: (string) $row->login,
            fullName: (string) $row->full_name,
            isAdmin: $this->isAdmin((int) $row->id),
            permissions: $this->permissions((int) $row->id),
            confirmCodeHash: $row->confirm_code !== null ? (string) $row->confirm_code : null,
            isImpersonated: $session->impersonated_by !== null,
        );
    }

    /**
     * Токен сессии: cookie (обычный путь админки) или заголовок
     * Authorization: Bearer. `?token=` — токен быстрого входа АИС — здесь
     * сознательно НЕ принимаем: админку сайта открывают из SPA, а токен в
     * адресе остался бы в журналах nginx рядом с правами на возврат денег.
     */
    private function tokenFromRequest(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $cookie = $request->cookie((string) config('ais_auth.cookie', 'ais_session'));

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /** Сроки те же, что у ais-api — shared/ais_auth.php. */
    private function sessionAlive(object $session): bool
    {
        /*
         * У сессии «работа от имени сотрудника» срок жёсткий и считается
         * от выдачи (колонка expires_at) — проверяем его раньше простоя,
         * как это делает EmployeeToken::isAlive в ais-api.
         */
        if (($session->expires_at ?? null) !== null
            && strtotime((string) $session->expires_at) < now()->timestamp) {
            return false;
        }

        $idle = (int) config('ais_auth.session_idle_minutes');
        $max = (int) config('ais_auth.session_max_minutes');

        $lastUsed = $session->last_used_at ?? $session->created_at;

        if ($idle > 0 && $lastUsed !== null && strtotime((string) $lastUsed) < now()->subMinutes($idle)->timestamp) {
            return false;
        }

        return ! ($max > 0 && $session->created_at !== null
            && strtotime((string) $session->created_at) < now()->subMinutes($max)->timestamp);
    }

    /** Чаще раза в минуту базу этим не трогаем. */
    private function touchSession(object $session): void
    {
        $lastUsed = $session->last_used_at === null ? 0 : strtotime((string) $session->last_used_at);

        if ($lastUsed < now()->subMinute()->timestamp) {
            DB::connection('ais')->table('employee_tokens')
                ->where('id', $session->id)
                ->update(['last_used_at' => now()]);
        }
    }

    private function isAdmin(int $employeeId): bool
    {
        return DB::connection('ais')->table('employee_role')
            ->join('roles', 'roles.id', '=', 'employee_role.role_id')
            ->where('employee_role.employee_id', $employeeId)
            ->where('roles.is_system', true)
            ->exists();
    }

    /** @return array<int, string> */
    private function permissions(int $employeeId): array
    {
        $rows = DB::connection('ais')->table('employee_role')
            ->join('roles', 'roles.id', '=', 'employee_role.role_id')
            ->where('employee_role.employee_id', $employeeId)
            ->where('roles.is_system', false)
            ->pluck('roles.permissions');

        $permissions = [];
        foreach ($rows as $json) {
            foreach ((array) json_decode((string) $json, true) as $permission) {
                if (is_string($permission)) {
                    $permissions[$permission] = true;
                }
            }
        }

        return array_keys($permissions);
    }
}
