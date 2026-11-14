<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Роль сотрудника АИС: набор прав вида "<модуль>.<право>".
 *
 * Роль «Администратор» системная (is_system): её права не хранятся и не
 * редактируются — у неё есть всё, в том числе модули, которых ещё нет.
 */
class Role extends Model
{
    protected $fillable = ['name', 'description', 'permissions', 'is_system'];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_role');
    }

    /** Права роли; у системной — признак «все», разворачивать не нужно. */
    public function permissionList(): array
    {
        return $this->is_system ? [] : array_values($this->permissions ?? []);
    }
}
