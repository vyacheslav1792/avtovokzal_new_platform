<?php

namespace App\Support;

use App\Services\SalesApiException;

/**
 * Разбор ответа станции на возврат (аннулирование бланков).
 *
 * Общий для обоих путей возврата — админского (АИС) и по коду (сайт):
 * расходиться им здесь нельзя, это проверка «станция действительно
 * аннулировала бланки».
 */
class StationRefundResult
{
    /**
     * Станция приняла ВСЕ переданные бланки?
     *
     * Это и есть признак успеха — вместо суммы возврата (`total_refund`).
     * Прежняя проверка «сумма больше нуля» была опасной: станция честно
     * отдаёт ноль там, где по её ведомости возвращать нечего — бесплатная
     * багажная квитанция (`price_bag = 0`), полное удержание. Мы же
     * считали это ошибкой и бросали исключение ПОСЛЕ того, как бланк на
     * станции уже аннулирован, не создав записи возврата: бланк пропадал
     * из ведомости, а деньги покупателю не возвращались.
     *
     * Так 17.11.2026 потерялся возврат по заказу
     * c57035943d07716aebbe4384f0819f (билет 112657019984/099900079,
     * АДАМЕНКО, 6935 ₽): отдельный возврат бесплатного багажа ответил
     * нулём, записи не оставил — а следующий возврат билета наткнулся на
     * уже аннулированную квитанцию.
     *
     * Старые сборки sales-api ключа `blanks` не отдают; тогда проверять
     * нечего — отказ по бланку там и так превращался в исключение.
     *
     * @param  array<string, mixed>  $result  тело `data` ответа sales-api
     */
    public static function assertAllBlanksAnnulled(array $result): void
    {
        foreach (self::rejectedBlanks($result) as $rejected) {
            throw new SalesApiException(
                'Станция не приняла бланк '.$rejected['serial'].'/'.$rejected['number']
                    .': '.($rejected['error'] ?? 'без причины'),
                'refund_rejected',
                422,
                ['blanks' => $result['blanks']],
            );
        }
    }

    /**
     * Бланки, которые станция отклонила.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public static function rejectedBlanks(array $result): array
    {
        $blanks = $result['blanks'] ?? null;

        if (! is_array($blanks)) {
            return [];
        }

        return array_values(array_filter(
            $blanks,
            fn ($b) => is_array($b) && ! ($b['ok'] ?? false),
        ));
    }
}
