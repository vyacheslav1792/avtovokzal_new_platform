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
        /**
         * Сессия выдана администратором «от имени» этого сотрудника
         * (АИС, право employees.impersonate). Такая сессия приходит и
         * сюда — cookie одна на SPA и оба API, — поэтому админские
         * маршруты сайта из неё только читают: правка страницы или
         * возврат денег оказались бы в журнале на имя сотрудника,
         * который их не делал.
         */
        public readonly bool $isImpersonated = false,
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
