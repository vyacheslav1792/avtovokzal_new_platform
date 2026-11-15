<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\FiscalReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Фискальный чек покупки через KKM API (сервис-прослойка к KkmServer).
 *
 * Это ВТОРОЙ заход: при оформлении чек сначала пробуют пробить сразу
 * (FiscalReceiptService::registerNow, ожидание 5 секунд) — чтобы
 * фискальный признак попал в билет, который покупатель открывает тут же.
 * В очередь джоб попадает, только если быстро не получилось.
 *
 * Ключ идемпотентности sale:{uuid} — повторный запуск джобы (retry,
 * дубль в очереди) не пробьёт второй чек.
 */
class RegisterFiscalReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public readonly string $orderId) {}

    public function handle(FiscalReceiptService $receipts): void
    {
        $order = Order::findOrFail($this->orderId);

        // Чек мог уже пробиться синхронно при оформлении — тогда делать
        // нечего (ключ идемпотентности тот же, второй чек всё равно не
        // пройдёт, но и дёргать ККТ незачем).
        if ($order->fiscal_sign) {
            return;
        }

        $receipt = $receipts->send($order);

        if ($receipt['status'] === 'pending') {
            $this->release($this->backoff); // ККТ ещё пробивает — спросим позже

            return;
        }

        if ($receipt['status'] !== 'registered') {
            throw new \RuntimeException('Ошибка фискализации: '.($receipt['error'] ?? 'unknown'));
        }

        $order->update(['fiscal_sign' => $receipt['fiscal_sign'] ?? $receipt['check_number']]);
    }
}
