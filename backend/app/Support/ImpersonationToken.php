<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Признак сессии «сотрудник вошёл в кабинет покупателя из АИС».
 *
 * ЛОВУШКА, из-за которой это вынесено в один класс: проверять признак
 * через `$token->can('impersonated')` НЕЛЬЗЯ. Обычный токен кабинета
 * создаётся без списка способностей, то есть с `['*']`, а `can()` у
 * Sanctum на `*` отвечает ДА на любой вопрос — поэтому «от имени
 * покупателя» выглядел КАЖДЫЙ вошедший покупатель: сайт показывал ему
 * полосу чужого кабинета, покупка и возврат отвечали 403, а сессия
 * жила 30 минут вместо полугода.
 *
 * Поэтому признак — ЯВНОЕ наличие способности в списке.
 */
final class ImpersonationToken
{
    public const ABILITY = 'impersonated';

    public static function is(mixed $token): bool
    {
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return in_array(self::ABILITY, (array) $token->abilities, true);
    }
}
