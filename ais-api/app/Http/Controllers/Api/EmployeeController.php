<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\Role;
use App\Rules\StrongPassword;
use App\Services\EmployeePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Сотрудники АИС: кого пускаем в систему и с какими ролями.
 *
 * Первый администратор заводится командой `php artisan ais:admin` (на пустой
 * системе входить ещё некому), остальных заводит и правит администратор здесь.
 */
class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeePresenter $presenter) {}

    public function index(): JsonResponse
    {
        $employees = Employee::with('roles')->orderBy('login')->get();

        return response()->json([
            'ok' => true,
            'employees' => $employees->map(fn (Employee $e) => $this->presenter->forAdmin($e))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'employee' => $this->presenter->forAdmin($this->find($id)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request, null);

        $employee = new Employee;
        $employee->setLogin($data['login']);
        $this->assertLoginFree($employee->login_normalized, null);

        $employee->fill([
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'position' => trim((string) ($data['position'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $employee->password = trim((string) $data['password']);

        if (($data['confirm_code'] ?? '') !== '') {
            $employee->confirm_code = $data['confirm_code'];
        }

        $employee->save();
        $this->syncRoles($employee, $data['role_ids'] ?? [], $request);

        $fastToken = null;
        if (($data['fast_token_enabled'] ?? false)) {
            $fastToken = $this->presenter->setFastToken($employee, $data['fast_token'] ?? null);
        }

        return response()->json([
            'ok' => true,
            'employee' => $this->presenter->forAdmin($employee->fresh('roles')),
            // Показывается один раз — дальше токен хранится только в шифре.
            'fast_token' => $fastToken,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($id);
        $data = $this->validateData($request, $employee);

        $employee->setLogin($data['login']);
        $this->assertLoginFree($employee->login_normalized, $employee->id);

        // Сам себя отключить нельзя: администратор запер бы систему,
        // а включить обратно было бы некому.
        $isActive = (bool) ($data['is_active'] ?? true);
        if (! $isActive && $employee->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'is_active' => 'Нельзя отключить собственную учётную запись.',
            ]);
        }

        $employee->fill([
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'position' => trim((string) ($data['position'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')),
            'is_active' => $isActive,
        ])->save();

        $this->syncRoles($employee, $data['role_ids'] ?? [], $request);

        return response()->json([
            'ok' => true,
            'employee' => $this->presenter->forAdmin($employee->fresh('roles')),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($id);

        if ($employee->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'id' => 'Нельзя удалить собственную учётную запись.',
            ]);
        }

        $this->assertAdminsRemain($employee, []);
        $employee->delete();

        return response()->json(['ok' => true]);
    }

    /** Сброс пароля сотрудника администратором (своего — через профиль). */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($id);

        $data = $request->validate([
            'password' => ['required', 'string', new StrongPassword([
                $employee->login, $employee->full_name, $employee->email,
            ])],
        ], ['password.required' => 'Придумайте пароль.']);

        $employee->password = trim($data['password']);
        $employee->save();

        // Старые сессии гасим: пароль сбрасывают, когда доступ надо отобрать.
        $employee->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    /** Сброс кода подтверждения опасных действий. */
    public function resetConfirmCode(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($id);

        $data = $request->validate([
            'code' => [
                'required', 'string',
                'min:'.config('ais_auth.confirm_code.min'),
                'max:'.config('ais_auth.confirm_code.max'),
            ],
        ], [
            'code.required' => 'Придумайте код подтверждения.',
            'code.min' => 'Код короче :min знаков.',
            'code.max' => 'Код длиннее :max знаков.',
        ]);

        $employee->confirm_code = $data['code'];
        $employee->save();

        return response()->json(['ok' => true]);
    }

    /** Перевыпуск токена быстрого входа (значение видно один раз). */
    public function resetFastToken(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($id);

        $data = $request->validate(['token' => ['nullable', 'string']]);

        return response()->json([
            'ok' => true,
            'token' => $this->presenter->setFastToken($employee, $data['token'] ?? null),
        ]);
    }

    /** Отключить токен быстрого входа сотруднику. */
    public function deleteFastToken(int $id): JsonResponse
    {
        $this->find($id)->forceFill(['fast_token' => null, 'fast_token_hash' => null])->save();

        return response()->json(['ok' => true]);
    }

    /** Выбросить сотрудника из всех сессий. */
    public function logoutSessions(int $id): JsonResponse
    {
        $this->find($id)->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    private function validateData(Request $request, ?Employee $employee): array
    {
        // Логин не ограничиваем по составу: русские буквы, точки, дефисы —
        // как кому удобно. Проверяем только длину и уникальность.
        $rules = [
            'login' => ['required', 'string', 'min:2', 'max:150'],
            'full_name' => ['nullable', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
            'confirm_code' => [
                'nullable', 'string',
                'min:'.config('ais_auth.confirm_code.min'),
                'max:'.config('ais_auth.confirm_code.max'),
            ],
            'fast_token_enabled' => ['nullable', 'boolean'],
            'fast_token' => ['nullable', 'string'],
        ];

        // Пароль задаётся при создании; существующему его меняют отдельно
        // (сброс пароля), чтобы случайная правка профиля не сбила вход.
        if ($employee === null) {
            $rules['password'] = ['required', 'string', new StrongPassword([$request->input('login')])];
        }

        return $request->validate($rules, [
            'login.required' => 'Укажите логин.',
            'password.required' => 'Придумайте пароль.',
            'code.min' => 'Код короче :min знаков.',
        ]);
    }

    private function find(int $id): Employee
    {
        $employee = Employee::with('roles')->find($id);

        abort_if(! $employee, response()->json([
            'ok' => false,
            'error' => 'Сотрудник не найден.',
        ], 404));

        return $employee;
    }

    private function assertLoginFree(string $normalized, ?int $exceptId): void
    {
        $exists = Employee::where('login_normalized', $normalized)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'login' => 'Такой логин уже занят (регистр и пробелы не считаются).',
            ]);
        }
    }

    /** @param  array<int, int>  $roleIds */
    private function syncRoles(Employee $employee, array $roleIds, Request $request): void
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

        if ($employee->id === $request->user()->id) {
            $this->assertNotDroppingOwnAdmin($employee, $roleIds);
        }

        $this->assertAdminsRemain($employee, $roleIds);

        $employee->roles()->sync($roleIds);
        $employee->load('roles');
    }

    /** Себя администратором лишать нельзя — продолжать было бы некому. */
    private function assertNotDroppingOwnAdmin(Employee $employee, array $roleIds): void
    {
        $keepsAdmin = Role::whereIn('id', $roleIds)->where('is_system', true)->exists();

        if ($employee->isAdmin() && ! $keepsAdmin) {
            throw ValidationException::withMessages([
                'role_ids' => 'Нельзя снять с себя роль администратора.',
            ]);
        }
    }

    /**
     * В системе обязан остаться хотя бы один действующий администратор:
     * иначе ни ролей, ни сотрудников больше никто не изменит, и останется
     * только `php artisan ais:admin` на сервере.
     *
     * @param  array<int, int>  $roleIds  роли, которые будут у сотрудника
     */
    private function assertAdminsRemain(Employee $employee, array $roleIds): void
    {
        if (! $employee->isAdmin()) {
            return;
        }

        if (Role::whereIn('id', $roleIds)->where('is_system', true)->exists()) {
            return;
        }

        $others = Employee::where('id', '!=', $employee->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('is_system', true))
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([
                'role_ids' => 'Это последний администратор системы — у него нельзя снять роль администратора и его нельзя удалить.',
            ]);
        }
    }
}
