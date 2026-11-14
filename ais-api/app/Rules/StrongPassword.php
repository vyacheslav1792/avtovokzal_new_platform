<?php

namespace App\Rules;

use App\Support\KeyboardLayoutTranslator;
use App\Support\PasswordStrengthEstimator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Пароль сотрудника АИС должен быть трудно угадываемым.
 *
 * Состав символов не проверяется намеренно: требование «заглавная + цифра +
 * спецсимвол» пропускает Пароль123! и отсекает удобную длинную фразу. Вместо
 * этого считается устойчивость к подбору (zxcvbn): словарные слова, даты,
 * клавиатурные дорожки, повторы и замены букв цифрами удешевляют пароль,
 * длина и непредсказуемость — удорожают.
 *
 * Перенесено из проекта api.gotov.org (StrongPasswordRule).
 */
class StrongPassword implements ValidationRule
{
    /** @param  list<string|null>  $userInputs  данные владельца: логин, ФИО, e-mail */
    public function __construct(private readonly array $userInputs = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Пароль должен быть строкой.');

            return;
        }

        // Пробелы по краям срезаются и при установке, и при проверке —
        // иначе пароль с невидимым пробелом из буфера обмена потом не ввести.
        $password = trim($value);
        $min = PasswordStrengthEstimator::minLength();

        if (mb_strlen($password) < $min) {
            $fail("Пароль должен быть не короче $min символов.");

            return;
        }

        // bcrypt молча обрезает всё после 72 байт, а русская буква занимает два:
        // без этой проверки часть длинного пароля не участвовала бы в проверке.
        if (strlen($password) > PasswordStrengthEstimator::MAX_BYTES) {
            $fail('Пароль слишком длинный: не больше '.PasswordStrengthEstimator::MAX_BYTES
                .' байт (русская буква занимает два).');

            return;
        }

        $result = app(PasswordStrengthEstimator::class)->estimate($password, $this->userInputs);

        if ($result->isStrong()) {
            return;
        }

        $fail($this->buildMessage($password, $result->warning, $result->suggestions));
    }

    /** @param  list<string>  $suggestions */
    private function buildMessage(string $password, ?string $warning, array $suggestions): string
    {
        $message = 'Такой пароль слишком легко подобрать.';

        if ($warning !== null && $warning !== '') {
            $message .= ' '.$warning;
        }

        if ($this->looksLikeWrongLayout($password)) {
            $message .= ' Слово, набранное в другой раскладке, подбирается так же быстро, как обычное.';
        }

        $hint = $suggestions[0] ?? 'Возьмите несколько несвязанных слов или сделайте пароль длиннее и непредсказуемее.';

        return $message.' '.$hint;
    }

    /** Латиница, за которой стоит русское слово («gfhjkm» — «пароль»). */
    private function looksLikeWrongLayout(string $password): bool
    {
        return KeyboardLayoutTranslator::latinToCyrillic($password) !== $password
            && preg_match('/^[\p{Latin}\p{P}0-9]+$/u', $password) === 1;
    }
}
