<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Services\EmployeePresenter;
use App\Services\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * Роли сотрудников АИС и каталог прав, из которого роль собирается.
 *
 * Роль «Администратор» системная: права у неё не хранятся (ей доступно всё,
 * включая модули, которых ещё нет) и их нельзя изменить — иначе очередной
 * отчёт пришлось бы вручную дописывать администратору в права.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly EmployeePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'roles' => Role::orderByDesc('is_system')->orderBy('name')->get()
                ->map(fn (Role $role) => $this->presenter->role($role))->all(),
            // Каталог отдаём вместе со списком: редактор роли рисует из
            // него галочки, и отдельный запрос за ним только мешает.
            'catalog' => $this->modules->catalog(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $role = Role::create([
            'name' => $data['name'],
            'description' => trim((string) ($data['description'] ?? '')),
            'permissions' => $this->filterPermissions($data['permissions'] ?? []),
            'is_system' => false,
        ]);

        return response()->json(['ok' => true, 'role' => $this->presenter->role($role)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = $this->find($id);

        if ($role->is_system) {
            throw ValidationException::withMessages([
                'name' => 'Системная роль «Администратор» не изменяется: ей доступно всё.',
            ]);
        }

        $data = $this->validateData($request);

        $role->update([
            'name' => $data['name'],
            'description' => trim((string) ($data['description'] ?? '')),
            // Набор прав заменяется целиком — как в остальных редакторах
            // админки (меню, контакты, антиспам-словарь).
            'permissions' => $this->filterPermissions($data['permissions'] ?? []),
        ]);

        return response()->json(['ok' => true, 'role' => $this->presenter->role($role)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = $this->find($id);

        if ($role->is_system) {
            throw ValidationException::withMessages([
                'id' => 'Системную роль «Администратор» удалить нельзя.',
            ]);
        }

        if ($role->employees()->exists()) {
            throw ValidationException::withMessages([
                'id' => 'Роль назначена сотрудникам — сначала снимите её с них.',
            ]);
        }

        $role->delete();

        return response()->json(['ok' => true]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:150'],
        ], ['name.required' => 'Назовите роль.']);
    }

    /**
     * Оставляем только существующие права: модуль могли убрать (отчёт
     * переименовали), и его право в роли дальше жить не должно.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private function filterPermissions(array $permissions): array
    {
        $known = $this->modules->permissionKeys();

        return array_values(array_unique(array_filter(
            array_map('strval', $permissions),
            fn (string $permission) => in_array($permission, $known, true),
        )));
    }

    private function find(int $id): Role
    {
        $role = Role::find($id);

        abort_if(! $role, response()->json(['ok' => false, 'error' => 'Роль не найдена.'], 404));

        return $role;
    }
}
