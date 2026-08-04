<?php

namespace App\Services;

use App\Models\SpamRule;

/**
 * Антиспам формы обратной связи (перенос словаря legacy — длинной цепочки
 * preg_match в avtovokzal57/url/contact.php).
 *
 * Отличие от legacy: там сработавшее правило означало, что письмо просто
 * не уйдёт и обращение исчезнет. Здесь сообщение всё равно сохраняется,
 * помечается как спам и остаётся видно оператору в АИС — ложное
 * срабатывание словаря больше не теряет обращение пассажира.
 */
class SpamFilter
{
    /**
     * Первое сработавшее правило или null, если сообщение чистое.
     *
     * @param  array{name?: ?string, email?: ?string, body?: ?string}  $message
     */
    public function match(array $message): ?string
    {
        foreach (SpamRule::active() as $rule) {
            $subject = $this->subject($message, $rule['scope']);

            if ($this->hits($rule, $subject)) {
                return $rule['note'] ?: $this->describe($rule);
            }
        }

        return null;
    }

    /** Текст, к которому применяется правило. */
    private function subject(array $message, string $scope): string
    {
        return match ($scope) {
            'name' => (string) ($message['name'] ?? ''),
            'email' => (string) ($message['email'] ?? ''),
            'any' => trim(implode("\n", [
                $message['name'] ?? '',
                $message['email'] ?? '',
                $message['body'] ?? '',
            ])),
            default => (string) ($message['body'] ?? ''),
        };
    }

    private function hits(array $rule, string $text): bool
    {
        $pattern = (string) ($rule['pattern'] ?? '');

        return match ($rule['kind']) {
            SpamRule::KIND_SUBSTRING => $pattern !== ''
                && mb_stripos($text, $pattern) !== false,

            // Правило пишется без ограничителей — как в legacy словаре
            // («флакон.*»), они добавляются здесь.
            SpamRule::KIND_REGEX => ($regex = self::compile($pattern)) !== null
                && @preg_match($regex, $text) === 1,

            // В legacy сообщение уходило только при условии
            // «кириллических букв строго больше латинских».
            SpamRule::KIND_LATIN => ! $this->cyrillicDominates($text),

            default => false,
        };
    }

    /**
     * Готовое регулярное выражение из образца правила (образец пишется без
     * ограничителей — как в словаре legacy: «флакон.*», «http(s)?:\/\/…»).
     *
     * Разделитель подбираем так, чтобы он не встречался в образце: слепо
     * экранировать «/» нельзя — в legacy-паттерне ссылки слэши уже
     * экранированы, и второе экранирование превращало «\/» в «\\/»,
     * то есть в обратный слэш, отчего правило переставало ловить ссылки.
     *
     * @return string|null null — образец пустой или выражение битое
     */
    public static function compile(?string $pattern): ?string
    {
        $pattern = (string) $pattern;

        if ($pattern === '') {
            return null;
        }

        foreach (['/', '~', '#', '%', '!', '@'] as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                $regex = $delimiter.$pattern.$delimiter.'iu';

                return @preg_match($regex, '') === false ? null : $regex;
            }
        }

        // В образце есть все разделители сразу — экранируем целиком:
        // такое «регулярное выражение» ищется как обычная подстрока.
        $regex = '/'.preg_quote($pattern, '/').'/iu';

        return @preg_match($regex, '') === false ? null : $regex;
    }

    private function cyrillicDominates(string $text): bool
    {
        preg_match_all('/[а-яА-ЯёЁ]/u', $text, $cyrillic);
        preg_match_all('/[a-zA-Z]/u', $text, $latin);

        return count($cyrillic[0]) > count($latin[0]);
    }

    /** Понятная причина, когда у правила не заполнено пояснение. */
    private function describe(array $rule): string
    {
        return match ($rule['kind']) {
            SpamRule::KIND_LATIN => 'Латиницы в тексте больше, чем кириллицы',
            default => 'Правило антиспама: '.$rule['pattern'],
        };
    }
}
