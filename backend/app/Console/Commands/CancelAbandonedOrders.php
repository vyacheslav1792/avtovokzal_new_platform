<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\PaymentGateway;
use App\Services\SalesApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Voronkovich\SberbankAcquiring\OrderStatus;

/**
 * Брошенные покупки: деньги списаны, билетов нет.
 *
 * Перенос legacy `avtovokzal57/refund.php`, который крутился по расписанию.
 * Покупатель ушёл на шлюз, оплатил и не вернулся на сайт — оформления не
 * произошло, а деньги у нас. Билеты за него НЕ оформляем: он мог об этой
 * покупке и не знать (закрыл вкладку, разрядился телефон). Возвращаем деньги,
 * как и legacy.
 *
 * **ЛОВУШКА отмены (`reverse`).** Шлюз умеет отменять платёж только внутри
 * того же операционного дня: оплата в 23:50 и отмена в 00:50 — это уже разные
 * дни, и отмена не пройдёт. Поэтому при отказе отмены делаем полный возврат
 * (`refund`) — он работает и задним числом, но идёт дольше и виден
 * покупателю отдельной операцией по карте. Поэтому возврат именно аварийный,
 * а не основной путь.
 *
 * Не удалось ни то, ни другое — заказ НЕ трогаем (деньги остаются у нас, и
 * решать такое должен человек) и пишем на support, как делала `failnotify`.
 */
class CancelAbandonedOrders extends Command
{
    protected $signature = 'orders:cancel-abandoned
        {--dry-run : только показать, что будет сделано}';

    protected $description = 'Вернуть деньги за оплаченные, но не оформленные заказы (перенос legacy refund.php)';

    public function handle(PaymentGateway $payments, SalesApiClient $salesApi): int
    {
        $after = (int) config('orders.abandoned.after_minutes');
        $maxAge = (int) config('orders.abandoned.max_age_hours');
        $dryRun = (bool) $this->option('dry-run');

        $abandoned = Order::query()
            ->whereIn('status', [Order::STATUS_PAYMENT_REGISTERED, Order::STATUS_PAID])
            ->whereNotNull('sber_order_id')
            // Билетов нет — иначе это оформленная покупка (legacy: `bilets is null`).
            ->whereDoesntHave('tickets')
            ->where('created_at', '<=', now()->subMinutes($after))
            ->where('created_at', '>=', now()->subHours($maxAge))
            ->orderBy('created_at')
            ->get();

        if ($abandoned->isEmpty()) {
            $this->info('Брошенных заказов нет.');

            return self::SUCCESS;
        }

        $this->info("Брошенных заказов: {$abandoned->count()}".($dryRun ? ' (сухой прогон)' : ''));

        foreach ($abandoned as $order) {
            try {
                $this->process($order, $payments, $salesApi, $dryRun);
            } catch (\Throwable $e) {
                // Один проблемный заказ не должен останавливать остальные.
                $this->error("{$order->id}: {$e->getMessage()}");
                Log::error('Брошенный заказ: разбор не удался', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function process(
        Order $order,
        PaymentGateway $payments,
        SalesApiClient $salesApi,
        bool $dryRun,
    ): void {
        $gateway = (int) ($payments->statusExtended((string) $order->sber_order_id)['orderStatus'] ?? -1);

        if ($gateway === OrderStatus::DEPOSITED) {
            $this->line("{$order->id}: оплачен и не оформлен — возвращаем деньги");

            if ($dryRun) {
                return;
            }

            $this->returnMoney($order, $payments, $salesApi);

            return;
        }

        if ($gateway === OrderStatus::CREATED) {
            // Не оплачен: деньги не списывались, шлюз закроет заказ сам.
            // Снимаем бронь, чтобы места не висели занятыми.
            $this->line("{$order->id}: не оплачен — снимаем бронь");

            if (! $dryRun) {
                $this->releaseSeats($order, $salesApi);
                $order->update([
                    'status' => Order::STATUS_CANCELLED,
                    'status_reason' => 'Оплата не поступила в отведённое время',
                ]);
            }

            return;
        }

        // Отклонён, отменён, возвращён — платежа у нас нет (legacy: STATUS=-3).
        $this->line("{$order->id}: шлюз вернул статус {$gateway} — помечаем как несостоявшийся");

        if (! $dryRun) {
            $this->releaseSeats($order, $salesApi);
            $order->update([
                'status' => Order::STATUS_FAILED,
                'status_reason' => "Платёж не состоялся, статус шлюза: {$gateway}",
            ]);
        }
    }

    /**
     * Сначала отмена, при отказе — возврат. См. ловушку в шапке класса.
     */
    private function returnMoney(Order $order, PaymentGateway $payments, SalesApiClient $salesApi): void
    {
        $sberOrderId = (string) $order->sber_order_id;

        try {
            $payments->reverse($sberOrderId);
            $this->finish($order, $salesApi, 'Платёж отменён: билеты не были оформлены');

            return;
        } catch (\Throwable $reverseError) {
            Log::warning('Брошенный заказ: отмена платежа не прошла, пробуем возврат', [
                'order_id' => $order->id,
                'error' => $reverseError->getMessage(),
            ]);

            try {
                $payments->refund($sberOrderId, (int) round((float) $order->total_price * 100));
                $this->finish($order, $salesApi, 'Платёж возвращён: билеты не были оформлены, отмена не прошла');

                return;
            } catch (\Throwable $refundError) {
                $this->notifySupport($order, $reverseError->getMessage(), $refundError->getMessage());
            }
        }
    }

    /** Деньги вернулись — снимаем бронь и закрываем заказ. */
    private function finish(Order $order, SalesApiClient $salesApi, string $reason): void
    {
        $this->releaseSeats($order, $salesApi);

        $order->update([
            'status' => Order::STATUS_CANCELLED,
            'status_reason' => $reason,
        ]);

        $this->info("{$order->id}: {$reason}");
        Log::info('Брошенный заказ закрыт', ['order_id' => $order->id, 'reason' => $reason]);
    }

    /**
     * Бронь мест держится всего ~24 минуты и к этому моменту обычно уже
     * истекла — но снять её явно дешевле, чем гадать.
     */
    private function releaseSeats(Order $order, SalesApiClient $salesApi): void
    {
        if (! $order->reservation_id) {
            return;
        }

        try {
            $salesApi->cancelReservation((string) $order->reservation_id);
        } catch (\Throwable $e) {
            Log::info('Брошенный заказ: бронь уже снята или недоступна', [
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
            "Заказ в шлюзе: {$order->sber_order_id}",
            "Сумма: {$order->total_price} ₽",
            "Покупатель: {$order->email}".($order->phone ? ", {$order->phone}" : ''),
            "Рейс: {$order->from_name} → {$order->to_name}, {$order->date_start->format('d.m.Y')} {$order->time_start}",
            "Создан: {$order->created_at->format('d.m.Y H:i')}",
            '',
            "Отмена платежа: {$reverseError}",
            "Возврат платежа: {$refundError}",
        ]);

        $this->error("{$order->id}: вернуть деньги не удалось — отправлено на support");
        Log::error('Брошенный заказ: деньги вернуть не удалось', [
            'order_id' => $order->id,
            'reverse_error' => $reverseError,
            'refund_error' => $refundError,
        ]);

        $to = config('orders.abandoned.alert_email');

        if (! $to) {
            return;
        }

        try {
            // Письмо синхронно: воркер очереди писем на проде не запущен,
            // а это сообщение нужно доставить обязательно.
            Mail::raw($message, fn ($mail) => $mail
                ->to($to)
                ->subject('Не удалось вернуть деньги за неоформленный билет — '.config('site.brand')));
        } catch (\Throwable $e) {
            Log::error('Брошенный заказ: не удалось отправить письмо на support', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
