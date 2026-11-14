<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeToken;
use App\Models\Role;
use App\Support\IpNetworks;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Как сотрудник и роль выглядят в ответах API, и как задаются секреты,
 * которые нельзя просто присвоить полю (токен быстрого входа хранится
 * сразу в двух видах — шифром и хэшем).
 */
class EmployeePresenter
{
    /** Сведения о себе: с правами и признаками заполненности секретов. */
    public function self(Employee $employee): array
    {
        $employee->loadMissing('roles');

        return $this->common($employee) + [
            'is_admin' => $employee->isAdmin(),
            'permissions' => $employee->permissions(),
            // Сам код и токен не отдаём — только есть он или нет.
            'has_confirm_code' => $employee->confirm_code !== null,
            'has_fast_token' => $employee->fast_token_hash !== null,
        ];
    }

    /** Сотрудник в списке администратора. */
    public function forAdmin(Employee $employee): array
    {
        $employee->loadMissing('roles');

        return $this->common($employee) + [
            'is_admin' => $employee->isAdmin(),
            'has_confirm_code' => $employee->confirm_code !== null,
            'has_fast_token' => $employee->fast_token_hash !== null,
            'roles' => $employee->roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => $role->is_system,
            ])->all(),
            'role_ids' => $employee->roles->pluck('id')->all(),
            'sessions' => $employee->tokens()->count(),
        ];
    }

    public function role(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'permissions' => $role->permissionList(),
            'employees' => $role->employees()->count(),
        ];
    }

    /**
     * Задать токен быстрого входа. Пустое значение — сервер придумывает сам.
     * Возвращает сам токен: показать его можно только сейчас.
     */
    public function setFastToken(Employee $employee, ?string $token): string
    {
        $token = trim((string) $token);
        $token = $token === '' ? EmployeeAuth::generateFastToken() : $token;

        $min = (int) config('ais_auth.fast_token.min');
        $max = (int) config('ais_auth.fast_token.max');

        // Токен едет в адресной строке, поэтому только латиница и цифры:
        // русские буквы и знаки в адресе кодируются процентами, и ссылку
        // уже не разобрать на глаз.
        if (! preg_match('/^[A-Za-z0-9]{'.$min.','.$max.'}$/', $token)) {
            throw ValidationException::withMessages([
                'token' => "Токен должен состоять из латинских букв и цифр, от $min до $max знаков.",
            ]);
        }

        $hash = EmployeeToken::hash($token);

        if (Employee::where('fast_token_hash', $hash)->where('id', '!=', $employee->id)->exists()) {
            throw ValidationException::withMessages([
                'token' => 'Такой токен уже занят другим сотрудником. Придумайте другой.',
            ]);
        }

        $employee->forceFill(['fast_token' => $token, 'fast_token_hash' => $hash])->save();

        return $token;
    }

    /** Действует ли вход по токену в сети, из которой пришёл запрос. */
    public function tokenNetworkAllowed(Request $request): bool
    {
        return IpNetworks::matches($request->ip(), (array) config('ais_auth.token_networks'));
    }

    private function common(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'login' => $employee->login,
            'full_name' => $employee->full_name,
            'position' => $employee->position,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'is_active' => $employee->is_active,
            'last_login_at' => $employee->last_login_at?->toIso8601String(),
            'last_login_ip' => $employee->last_login_ip,
        ];
    }
}
