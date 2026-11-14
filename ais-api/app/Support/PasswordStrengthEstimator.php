<?php

namespace App\Support;

use ZxcvbnPhp\Zxcvbn;

/**
 * Оценка стойкости пароля по zxcvbn.
 *
 * Считается не состав символов, а во сколько попыток обходится подбор: слово из
 * словаря, имя, дата, клавиатурная дорожка, повтор или замена «@» вместо «a»
 * удешевляют пароль, длина и непредсказуемость — удорожают. Поэтому длинная
 * фраза из обычных слов проходит, а Пароль123! — нет.
 *
 * Перенесено из проекта api.gotov.org без изменений логики; длина и порог
 * оценки берутся из shared/ais_auth.php, чтобы их можно было менять.
 */
class PasswordStrengthEstimator
{
    private ?Zxcvbn $zxcvbn = null;

    /** bcrypt использует только первые 72 байта пароля */
    public const MAX_BYTES = 72;

    /** Минимальная длина: короче любая фраза угадывается перебором */
    public static function minLength(): int
    {
        return (int) config('ais_auth.password.min', 10);
    }

    /** Порог оценки zxcvbn (0–4): 3 — «подбор занимает заметное время» */
    public static function minScore(): int
    {
        return (int) config('ais_auth.password.min_score', 3);
    }

    /**
     * @param  string  $password  пароль без краевых пробелов
     * @param  list<string|null>  $userInputs  данные владельца: email, логин, ФИО
     */
    public function estimate(string $password, array $userInputs = []): PasswordStrength
    {
        $inputs = $this->buildInputs($userInputs);
        $worst = null;

        // Пароль проверяется и «как есть», и прочитанным в другой раскладке:
        // «gfhjkm» — это слово «пароль», подбирается оно ровно так же быстро
        foreach ($this->variants($password) as $variant) {
            $result = $this->zxcvbn()->passwordStrength($variant, $inputs);

            if ($worst === null || $result['score'] < $worst['score']) {
                $worst = $result;
            }
        }

        $feedback = is_array($worst['feedback'] ?? null) ? $worst['feedback'] : [];
        $warning = $feedback['warning'] ?? '';
        $suggestions = $feedback['suggestions'] ?? [];

        return new PasswordStrength(
            (int) ($worst['score'] ?? 0),
            $this->translate(is_string($warning) ? $warning : ''),
            array_values(array_filter(array_map(
                fn (mixed $suggestion): ?string => $this->translate(is_string($suggestion) ? $suggestion : ''),
                is_array($suggestions) ? $suggestions : []
            ))),
        );
    }

    /** @return list<string> */
    private function variants(string $password): array
    {
        return array_values(array_unique([
            $password,
            KeyboardLayoutTranslator::latinToCyrillic($password),
            KeyboardLayoutTranslator::cyrillicToLatin($password),
        ]));
    }

    /**
     * @param  list<string|null>  $userInputs
     * @return list<string>
     */
    private function buildInputs(array $userInputs): array
    {
        $inputs = [];

        foreach ($userInputs as $input) {
            if ($input === null || trim($input) === '') {
                continue;
            }

            $inputs[] = $input;

            // email целиком совпадает редко, а вот его начало становится паролем часто
            if (str_contains($input, '@')) {
                $inputs[] = strstr($input, '@', true) ?: $input;
            }

            foreach (preg_split('/[\s._-]+/u', $input) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $inputs[] = $part;
                }
            }
        }

        return array_values(array_unique(array_merge($inputs, RussianPasswordDictionary::words())));
    }

    private function zxcvbn(): Zxcvbn
    {
        return $this->zxcvbn ??= new Zxcvbn;
    }

    /** Подсказки zxcvbn приходят по-английски, а видит их администратор */
    private function translate(string $message): ?string
    {
        if ($message === '') {
            return null;
        }

        return self::MESSAGES[$message] ?? $message;
    }

    /** @var array<string, string> */
    private const MESSAGES = [
        'This is a top-10 common password' => 'Это один из десяти самых частых паролей в мире.',
        'This is a top-100 common password' => 'Это один из ста самых частых паролей.',
        'This is a very common password' => 'Это очень распространённый пароль.',
        'This is similar to a commonly used password' => 'Пароль почти совпадает с одним из распространённых.',
        'A word by itself is easy to guess' => 'Одно слово подбирается быстро.',
        'Names and surnames by themselves are easy to guess' => 'Имя или фамилия сами по себе подбираются быстро.',
        'Common names and surnames are easy to guess' => 'Распространённые имена и фамилии подбираются быстро.',
        'Short keyboard patterns are easy to guess' => 'Короткие клавиатурные дорожки подбираются быстро.',
        'Straight rows of keys are easy to guess' => 'Подряд идущие клавиши подбираются быстро.',
        'Repeats like "aaa" are easy to guess' => 'Повторы вида «ааа» подбираются быстро.',
        'Repeats like "abcabcabc" are only slightly harder to guess than "abc"' => 'Повтор вида «абвабвабв» почти не сложнее, чем «абв».',
        'Sequences like abc or 6543 are easy to guess' => 'Последовательности вида abc или 6543 подбираются быстро.',
        'Recent years are easy to guess' => 'Недавние годы подбираются быстро.',
        'Dates are often easy to guess' => 'Даты обычно подбираются быстро.',
        'All-uppercase is almost as easy to guess as all-lowercase' => 'ПАРОЛЬ ЦЕЛИКОМ ЗАГЛАВНЫМИ почти не сложнее строчных букв.',
        "Capitalization doesn't help very much" => 'Заглавная первая буква почти не помогает.',
        "Reversed words aren't much harder to guess" => 'Слово задом наперёд почти не сложнее обычного.',
        "Predictable substitutions like '@' instead of 'a' don't help very much" => 'Предсказуемые замены вроде «@» вместо «a» почти не помогают.',
        'Add another word or two. Uncommon words are better.' => 'Добавьте ещё одно-два слова, лучше редких.',
        'Use a few words, avoid common phrases' => 'Возьмите несколько слов, избегая расхожих фраз.',
        'No need for symbols, digits, or uppercase letters' => 'Спецсимволы, цифры и заглавные буквы не обязательны.',
        'Avoid dates and years that are associated with you' => 'Не берите связанные с вами даты и годы.',
        'Avoid recent years' => 'Не берите недавние годы.',
        'Avoid years that are associated with you' => 'Не берите связанные с вами годы.',
        'Avoid repeated words and characters' => 'Не повторяйте слова и символы.',
        'Avoid sequences' => 'Не берите последовательности подряд идущих символов.',
        'Use a longer keyboard pattern with more turns' => 'Возьмите более длинную и ломаную клавиатурную дорожку.',
        'Capitalize more than the first letter' => 'Заглавной должна быть не только первая буква.',
        'Capitalize less than all the letters' => 'Не пишите весь пароль заглавными.',
    ];
}
