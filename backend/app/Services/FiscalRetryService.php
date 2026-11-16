<?php

namespace App\Services;

use App\Jobs\RegisterFiscalReceipt;
use App\Jobs\RegisterRefundReceipt;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Непробитые фискальные чеки: поиск и повторная фискализация за период.
 *
 * Зачем отдельный инструмент: чек ставится в очередь `receipts` и
 * пробивается с пятью попытками по минуте. Если ККТ в это время была
 * недоступна (нет связи, закрытая смена, кончилась бумага), попытки
 * заканчиваются — и операция остаётся без фискального документа, хотя
 * деньги прошли. По 54-ФЗ так оставлять нельзя, а раньше это можно было
 * заметить только глазами в отчёте.
 *
 * **ПЕРЕНЕСЁННЫЕ СО СТАРОГО САЙТА НЕ ТРОГАЕМ.** У них `legacy` не пусто:
 * чеки по ним пробивала прежняя система (на проде таких 363 покупки с
 * 2018 года и 38 возвратов). Пробить их заново значит выдать второй
 * фискальный документ по давно закрытой продаже — поэтому они только
 * показываются числом «пропущено», и пробить их кнопкой нельзя.
 */
class FiscalRetryService
{
    /** Защита от случайного «пробить всё»: сколько операций берём за раз. */
    private const MAX_PER_RUN = 100;

    /**
     * Что осталось без фискального чека за период.
     *
     * @return array<string, mixed>
     */
    public function pending(string $from, string $to): array
    {
        [$start, $end] = $this->range($from, $to);

        $orders = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereNull('fiscal_sign')
            ->whereNull('legacy')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get(['id', 'created_at', 'total_price', 'email', 'from_name', 'to_name', 'date_start']);

        $refunds = Refund::query()
            ->whereNotNull('money_refunded_at')
            ->whereNull('fiscal_sign')
            ->where('amount', '>', 0)
            ->whereBetween('created_at', [$start, $end])
            ->with('order:id,legacy,email')
            ->orderBy('created_at')
            ->get()
            // Возвраты по перенесённым заказам тоже не наши: чек по ним
            // пробивала прежняя система.
            ->filter(fn (Refund $r) => $r->order && $r->order->legacy === null && $r->legacy === null);

        $skipped = $this->skippedLegacy($start, $end);

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'orders' => $orders->map(fn (Order $o) => [
                'kind' => 'sale',
                'id' => $o->id,
                'created_at' => $o->created_at?->format('d.m.Y H:i'),
                'amount' => (float) $o->total_price,
                'title' => 'Покупка · '.$o->from_name.' → '.$o->to_name,
                'email' => $o->email,
                'refund_url' => '/back-ticket?order_id='.$o->id,
            ])->values()->all(),
            'refunds' => $refunds->map(fn (Refund $r) => [
                'kind' => 'refund',
                'id' => $r->id,
                'order_id' => $r->order_id,
                'created_at' => $r->created_at?->format('d.m.Y H:i'),
                'amount' => (float) $r->amount,
                'title' => $r->kindLabel().' · бланк '.$r->serial.' '.$r->number,
                'email' => $r->order?->email,
                'refund_url' => '/back-ticket?order_id='.$r->order_id
                    .'&serial='.$r->serial.'&number='.$r->number,
            ])->values()->all(),
            // Сколько в периоде перенесённых со старого сайта — их не
            // трогаем, но сказать о них надо, иначе цифры не сходятся.
            'skipped_legacy' => $skipped,
            'limit' => self::MAX_PER_RUN,
        ];
    }

    /**
     * Пробить чеки за период. Выполняется СИНХРОННО: сотрудник должен
     * видеть, что получилось по каждой операции, а не «поставлено в
     * очередь» (у которой свои пять попыток и час жизни воркера).
     *
     * @return array<string, mixed>
     */
    public function retry(string $from, string $to): array
    {
        $lock = Cache::lock('fiscal-retry', 600);

        if (! $lock->get()) {
            return [
                'started' => false,
                'message' => 'Фискализация уже выполняется — дождитесь её окончания.',
                'results' => [],
            ];
        }

        try {
            $pending = $this->pending($from, $to);
            $items = array_slice(
                array_merge($pending['orders'], $pending['refunds']),
                0,
                self::MAX_PER_RUN,
            );

            $results = [];

            foreach ($items as $item) {
                $results[] = $item + $this->fiscalize($item);
            }

            $ok = count(array_filter($results, fn (array $r) => $r['ok']));

            Log::info('Повторная фискализация за период', [
                'from' => $pending['from'],
                'to' => $pending['to'],
                'total' => count($results),
                'ok' => $ok,
            ]);

            return [
                'started' => true,
                'from' => $pending['from'],
                'to' => $pending['to'],
                'total' => count($results),
                'ok' => $ok,
                'failed' => count($results) - $ok,
                'skipped_legacy' => $pending['skipped_legacy'],
                'results' => $results,
                'message' => count($results) === 0
                    ? 'Непробитых чеков за этот период нет.'
                    : 'Обработано операций: '.count($results).', пробито: '.$ok
                        .', не удалось: '.(count($results) - $ok).'.',
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Одна операция: пробить чек и вернуть результат.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function fiscalize(array $item): array
    {
        try {
            if ($item['kind'] === 'sale') {
                RegisterFiscalReceipt::dispatchSync($item['id']);
                $sign = Order::find($item['id'])?->fiscal_sign;
            } else {
                RegisterRefundReceipt::dispatchSync($item['id']);
                $sign = Refund::find($item['id'])?->fiscal_sign;
            }

            return $sign
                ? ['ok' => true, 'fiscal_sign' => $sign, 'error' => null]
                : [
                    'ok' => false,
                    'fiscal_sign' => null,
                    // ККТ приняла команду, но признак ещё не вернула: такое
                    // бывает, когда она пробивает чек дольше нашего ожидания.
                    'error' => 'ККТ не вернула фискальный признак — повторите через минуту.',
                ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'fiscal_sign' => null,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        }
    }

    /** @return array<string, int> */
    private function skippedLegacy(Carbon $start, Carbon $end): array
    {
        return [
            'orders' => Order::query()
                ->where('status', Order::STATUS_COMPLETED)
                ->whereNull('fiscal_sign')
                ->whereNotNull('legacy')
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'refunds' => Refund::query()
                ->whereNotNull('money_refunded_at')
                ->whereNull('fiscal_sign')
                ->where('amount', '>', 0)
                ->whereBetween('created_at', [$start, $end])
                ->whereHas('order', fn ($q) => $q->whereNotNull('legacy'))
                ->count(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(string $from, string $to): array
    {
        return [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ];
    }
}
