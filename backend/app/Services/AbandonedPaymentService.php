<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Voronkovich\SberbankAcquiring\OrderStatus;

/**
 * Разбор покупок, за которые деньги списаны, а билетов нет.
 *
 * Перенос legacy `avtovokzal57/refund.php`. Билеты за покупателя НЕ
 * оформляем: он мог об этой покупке и не знать (закрыл вкладку, разрядился
 * телефон) — возвращаем деньги, как делал legacy.
 *
 * Одна и та же логика работает двумя путями: по расписанию
 * (`orders:cancel-abandoned`, каждые 15 минут) и кнопкой кассира в АИС
 * («Отменить платёж» на `/back-ticket`). Расходиться им нельзя — отмена
 * платежа должна выглядеть одинаково, кто бы её ни запустил.
 *
 * **Порядок операций: сначала отмена (`reverse`), и только при её отказе
 * полный возврат (`refund`).** Отмена для покупателя мягче: деньги
 * возвращаются без отдельной операции по карте. Шлюз умеет отменять платёж
 * только внутри того же операционного дня — оплата в 23:50 и отмена в
 * 00:50 это уже разные дни, и тогда остаётся возврат: он работает и задним
 * числом, но идёт дольше и виден покупателю отдельной строкой в выписке.
 *
 * **ЛОВУШКА: по УЖЕ СПИСАННОМУ платежу наш шлюз проводит `reverse.do` как
 * ВОЗВРАТ.** Вызов проходит без ошибки, но заказ в шлюзе получает статус 4
 * («возврат проведён») и `refundedAmount` на всю сумму — то есть отмены в
 * чистом виде не случилось. Проверено на проде 16.11.2026 (заказ
 * 01a1e420-…, 486,26 ₽): наш код записал «Платёж отменён», а шлюз показал
 * возврат. Поэтому ЧЕМ ЗАКОНЧИЛОСЬ, мы спрашиваем у шлюза и пишем по
 * факту: кассиру и покупателю важно, увидят ли они отдельную операцию по
 * карте и когда вернутся деньги.
 *
 * Не вышло ни то, ни другое — заказ НЕ трогаем (деньги остаются у нас,
 * решать человеку) и пишем на support, как делала `failnotify`.
 */
class AbandonedPaymentService
{
    public function __construct(
        private readonly PaymentGateway $payments,
        private readonly SalesApiClient $salesApi,
    ) {}

    /**
     * Заказы, которые надо разобрать.
     *
     * Кроме «нормальных» оплаченных без билетов сюда ОБЯЗАТЕЛЬНО попадают
     * заказы со статусом `failed`, у которых заполнен `paid_at`: статус
     * мог испортиться из-за помехи при дооформлении, и такой заказ раньше
     * не видел никто — деньги молча оставались у нас (ловилось на проде
     * 16.11.2026, заказ 01a1e420-… на 486,26 ₽: Мценск был недоступен).
     *
     * @return Collection<int, Order>
     */
    public function candidates(): Collection
    {
        $after = (int) config('orders.abandoned.after_minutes');
        $maxAge = (int) config('orders.abandoned.max_age_hours');

        return Order::query()
            ->whereNotNull('sber_order_id')
            // Билетов нет — иначе это оформленная покупка (legacy: `bilets is null`).
            ->whereDoesntHave('tickets')
            ->where(function ($q) {
                $q->whereIn('status', [Order::STATUS_PAYMENT_REGISTERED, Order::STATUS_PAID])
                    // Статус испорчен, но деньги списаны — страховка.
                    ->orWhere(fn ($q2) => $q2
                        ->where('status', Order::STATUS_FAILED)
                        ->whereNotNull('paid_at'));
            })
            ->where('created_at', '<=', now()->subMinutes($after))
            ->where('created_at', '>=', now()->subHours($maxAge))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Разобрать один заказ: вернуть деньги, снять бронь, закрыть.
     *
     * `$force` — запуск кассиром из АИС: там заказ выбирают руками, и
     * ждать «час с момента создания» незачем.
     *
     * @return array{action: string, message: string, gateway_status: int}
     */
    public function resolve(Order $order, ?string $by = null): array
    {
        $gateway = (int) ($this->payments->statusExtended((string) $order->sber_order_id)['orderStatus'] ?? -1);

        if ($gateway === OrderStatus::DEPOSITED) {
            return $this->returnMoney($order, $gateway, $by);
        }

        if ($gateway === OrderStatus::CREATED) {
            // Не оплачен: деньги не списывались, шлюз закроет заказ сам.
            // Снимаем бронь, чтобы места не висели занятыми.
            $this->releaseSeats($order);
            $this->close($order, Order::STATUS_CANCELLED, 'Оплата не поступила в отведённое время');

            return [
                'action' => 'not_paid',
                'message' => 'Платёж не поступал — заказ закрыт, бронь снята.',
                'gateway_status' => $gateway,
            ];
        }

        // Отклонён, отменён, уже возвращён — платежа у нас нет (legacy: STATUS=-3).
        $this->releaseSeats($order);
        $this->close($order, Order::STATUS_FAILED, "Платёж не состоялся, статус шлюза: {$gateway}");

        return [
            'action' => 'no_payment',
            'message' => "Денег по этому заказу у нас нет: шлюз отвечает статусом {$gateway}.",
            'gateway_status' => $gateway,
        ];
    }

    /** Сначала отмена, при отказе — возврат. См. ловушку в шапке класса. */
    private function returnMoney(Order $order, int $gateway, ?string $by): array
    {
        $sberOrderId = (string) $order->sber_order_id;
        $suffix = $by === null ? '' : " ({$by})";

        try {
            $this->payments->reverse($sberOrderId);

            /*
             * Спрашиваем шлюз, чем это обернулось: по уже списанному платежу
             * `reverse.do` проводится как полный возврат (статус 4). Писать
             * «отменён», когда на самом деле возврат, нельзя — от этого
             * зависит, что покупатель увидит по карте.
             */
            $refunded = $this->wasRefunded($sberOrderId);

            $this->finish($order, ($refunded
                ? 'Платёж возвращён: билеты не были оформлены'
                : 'Платёж отменён: билеты не были оформлены').$suffix);

            return [
                'action' => $refunded ? 'refunded_by_reverse' : 'reversed',
                'message' => $refunded
                    ? 'Деньги возвращены покупателю полностью. Шлюз провёл это возвратом, а не отменой '
                        .'(платёж уже был списан), поэтому в выписке покупателя появится отдельная '
                        .'операция и зачисление займёт до нескольких дней. Бронь мест снята, заказ закрыт.'
                    : 'Платёж отменён, деньги вернутся покупателю без отдельной операции по карте. '
                        .'Бронь мест снята, заказ закрыт.',
                'gateway_status' => $gateway,
            ];
        } catch (\Throwable $reverseError) {
            Log::warning('Отмена платежа не прошла, пробуем возврат', [
                'order_id' => $order->id,
                'by' => $by,
                'error' => $reverseError->getMessage(),
            ]);

            try {
                $this->payments->refund($sberOrderId, (int) round((float) $order->total_price * 100));
                $this->finish($order, 'Платёж возвращён: билеты не были оформлены, отмена не прошла'.$suffix);

                return [
                    'action' => 'refunded',
                    'message' => 'Отмена не прошла (операционный день шлюза уже закрыт), поэтому сделан '
                        .'полный возврат — он идёт дольше и будет видён покупателю отдельной операцией. '
                        .'Бронь мест снята, заказ закрыт.',
                    'gateway_status' => $gateway,
                ];
            } catch (\Throwable $refundError) {
                $this->notifySupport($order, $reverseError->getMessage(), $refundError->getMessage());

                return [
                    'action' => 'failed',
                    'message' => 'Ни отмена, ни возврат не прошли: '.$refundError->getMessage()
                        .'. Заказ оставлен как есть (деньги у нас), на support отправлено письмо.',
                    'gateway_status' => $gateway,
                ];
            }
        }
    }

    /**
     * Проверить у шлюза, чем закончилась отмена: возвратом (статус 4 или
     * ненулевой `refundedAmount`) или настоящей отменой. Ошибку опроса
     * считаем «отменой» — деньги в любом случае сняты с нас, а точную
     * формулировку кассир увидит в карточке заказа по статусу шлюза.
     */
    private function wasRefunded(string $sberOrderId): bool
    {
        try {
            $status = $this->payments->statusExtended($sberOrderId);
        } catch (\Throwable) {
            return false;
        }

        return (int) ($status['orderStatus'] ?? -1) === OrderStatus::REFUNDED
            || ((int) ($status['paymentAmountInfo']['refundedAmount'] ?? 0)) > 0;
    }

    /** Деньги вернулись — снимаем бронь и закрываем заказ. */
    private function finish(Order $order, string $reason): void
    {
        $this->releaseSeats($order);
        $this->close($order, Order::STATUS_CANCELLED, $reason);

        Log::info('Заказ без билетов закрыт, платёж отменён', [
            'order_id' => $order->id,
            'reason' => $reason,
        ]);
    }

    private function close(Order $order, string $status, string $reason): void
    {
        // Условный UPDATE: если заказ всё же успели оформить, `completed`
        // не затираем — иначе у покупателя есть билет, а заказ «отменён».
        Order::whereKey($order->id)
            ->where('status', '!=', Order::STATUS_COMPLETED)
            ->update(['status' => $status, 'status_reason' => $reason, 'updated_at' => now()]);
    }

    /**
     * Бронь мест держится всего ~24 минуты и к этому моменту обычно уже
     * истекла — но снять её явно дешевле, чем гадать.
     */
    private function releaseSeats(Order $order): void
    {
        if (! $order->reservation_id) {
            return;
        }

        try {
            $this->salesApi->cancelReservation((string) $order->reservation_id);
        } catch (\Throwable $e) {
            Log::info('Бронь уже снята или станция недоступна', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Деньги вернуть не удалось — разбираться человеку. Статус заказа НЕ
     * меняем: он так и остаётся оплаченным без билетов, и напоминание на
     * сайте по-прежнему предложит покупателю завершить оформление.
     */
    private function notifySupport(Order $order, string $reverseError, string $refundError): void
    {
        $message = implode(PHP_EOL, [
            'Заказ оплачен, билеты не оформлены, вернуть деньги автоматически не удалось.',
            '',
            "Заказ: {$order->id}",
            "Сумма: {$order->total_price} руб.",
            "Платёж в шлюзе: {$order->sber_order_id}",
            "Покупатель: {$order->email}, {$order->phone}",
            '',
            "Отмена не прошла: {$reverseError}",
            "Возврат не прошёл: {$refundError}",
        ]);

        Log::error('Брошенный заказ: деньги вернуть не удалось', [
            'order_id' => $order->id,
            'reverse_error' => $reverseError,
            'refund_error' => $refundError,
        ]);

        $to = (string) config('orders.abandoned.alert_email', config('site.support_email'));

        if ($to === '') {
            return;
        }

        try {
            Mail::raw($message, fn ($mail) => $mail->to($to)
                ->subject('Не удалось вернуть деньги за неоформленный заказ '.$order->id));
        } catch (\Throwable $e) {
            Log::error('Письмо на support не отправлено', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
