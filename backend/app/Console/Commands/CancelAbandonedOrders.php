<?php

namespace App\Console\Commands;

use App\Services\AbandonedPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Брошенные покупки: деньги списаны, билетов нет (перенос legacy
 * `avtovokzal57/refund.php`, крутился по расписанию).
 *
 * Сама логика — в `AbandonedPaymentService`: ту же работу делает кнопка
 * «Отменить платёж» в АИС, и расходиться им нельзя.
 */
class CancelAbandonedOrders extends Command
{
    protected $signature = 'orders:cancel-abandoned
        {--dry-run : только показать, что будет сделано}';

    protected $description = 'Вернуть деньги за оплаченные, но не оформленные заказы (перенос legacy refund.php)';

    public function handle(AbandonedPaymentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $orders = $service->candidates();

        if ($orders->isEmpty()) {
            $this->info('Брошенных заказов нет.');

            return self::SUCCESS;
        }

        $this->info("Брошенных заказов: {$orders->count()}".($dryRun ? ' (сухой прогон)' : ''));

        foreach ($orders as $order) {
            if ($dryRun) {
                $this->line("{$order->id}: статус {$order->status}, сумма {$order->total_price} — был бы разобран");

                continue;
            }

            try {
                $result = $service->resolve($order, by: 'автоматически');
                $this->line("{$order->id}: {$result['action']} — {$result['message']}");
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
}
