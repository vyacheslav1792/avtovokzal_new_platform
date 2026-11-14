<?php

namespace App\Models;

use App\Support\AisLogin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

/**
 * Сотрудник АИС (пользователь внутренней системы).
 *
 * Три секрета и у каждого своя задача:
 *   password     — вход по логину и паролю (bcrypt);
 *   confirm_code — подтверждение опасного действия (bcrypt, не показываем);
 *   fast_token   — вход по ссылке `?token=…` из доверенной сети. Он обязан
 *                  показываться владельцу (его вставляют в адрес), поэтому
 *                  хранится в шифре Laravel, а искать по нему позволяет
 *                  отдельный sha256 — Crypt у каждого шифрования даёт
 *                  разный результат, индексом по нему не найти.
 */
class Employee extends Model
{
    protected $fillable = [
        'login', 'login_normalized', 'full_name', 'position',
        'email', 'phone', 'password', 'is_active',
    ];

    protected $hidden = ['password', 'confirm_code', 'fast_token', 'fast_token_hash'];

    protected $casts = [
        'password' => 'hashed',
        'confirm_code' => 'hashed',
        'fast_token' => 'encrypted',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'employee_role');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(EmployeeToken::class);
    }

    /** Поиск по логину: регистр не важен, пробелы по краям срезаны. */
    public static function findByLogin(string $login): ?self
    {
        return static::where('login_normalized', AisLogin::normalize($login))->first();
    }

    /** Логин пишем и как ввели, и в нормализованном виде — сразу парой. */
    public function setLogin(string $login): void
    {
        $this->login = AisLogin::clean($login);
        $this->login_normalized = AisLogin::normalize($login);
    }

    /** Есть ли у сотрудника системная роль (ей доступно всё). */
    public function isAdmin(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->is_system);
    }

    /** Все права сотрудника из всех его ролей (у администратора — ['*']). */
    public function permissions(): array
    {
        if ($this->isAdmin()) {
            return ['*'];
        }

        $permissions = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissionList() as $permission) {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }

    /** Право вида "<модуль>.<право>" (например `pages.edit`). */
    public function can($permission, $arguments = []): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->isAdmin() || in_array($permission, $this->permissions(), true);
    }

    /** Код подтверждения опасного действия. Не задан — подтвердить нечем. */
    public function confirmCodeMatches(?string $code): bool
    {
        $code = trim((string) $code);

        return $code !== ''
            && $this->confirm_code !== null
            && Hash::check($code, $this->confirm_code);
    }
}
