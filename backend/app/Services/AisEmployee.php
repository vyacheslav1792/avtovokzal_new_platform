<?php

namespace App\Services;

/**
 * Сотрудник АИС, пришедший за админским API сайта. Не модель: сотрудники
 * живут в БД `ais` (ais-api), backend их только читает — см. AisAuth.
 */
class AisEmployee
{
    /** @param  array<int, string>  $permissions  ['*'] у администратора */
    public function __construct(
        public readonly int $id,
        public readonly string $login,
        public readonly string $fullName,
        public readonly bool $isAdmin,
        public readonly array $permissions,
        public readonly ?string $confirmCodeHash,
    ) {}

    /** Право вида "<модуль>.<право>" (например `pages.edit`). */
    public function can(string $permission): bool
    {
        return $this->isAdmin || in_array($permission, $this->permissions, true);
    }

    public function hasConfirmCode(): bool
    {
        return $this->confirmCodeHash !== null;
    }
}
