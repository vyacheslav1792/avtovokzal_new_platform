<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Services\SalesApiClient;
use Illuminate\Console\Command;

/**
 * Дочистить багажные бланки у билетов, возвращённых ДО исправления
 * «багаж аннулируется вместе с билетом».
 *
 * Те возвраты прошли с недочётом: деньги покупателю вернулись за место И за
 * багаж (сумма всегда считалась с багажом), а на станции аннулировалось
 * только пассажирское место — багажная квитанция оставалась проданной в
 * ведомости. Команда закрывает ровно эту разницу:
 *
 *  - на станции аннулирует багажные бланки (KS_ANN_TICKET_FROM_AW, admin_mode);
 *  - у нас пишет строку `refunds` kind=baggage с **нулевой суммой** и
 *    пометкой, что деньги отданы раньше в составе билета.
 *
 * Деньги НЕ возвращаются и чек НЕ пробивается — они уже возвращены и
 * пробиты возвратом билета. Поэтому и сумма нулевая: иначе отчёты показали
 * бы возврат, которого в банке не было.
 */
class AnnulBaggageBlanks extends Command
{
    protected $signature = 'refunds:annul-baggage
        {--order= : ID заказа (по умолчанию — все подходящие)}
        {--serial= : серия билета (вместе с --number)}
        {--number= : номер билета}
        {--include-legacy : включить возвраты, перенесённые со старого сайта (по умолчанию ОНИ ПРОПУСКАЮТСЯ)}
        {--dry-run : только показать, что будет сделано}';

    protected $description = 'Аннулировать на станции багажные бланки возвращённых билетов (без возврата денег)';

    public function handle(SalesApiClient $salesApi): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targets = $this->targets();

        if ($targets === []) {
            $this->info('Нечего делать: багажных бланков без аннулирования не найдено.');

            return self::SUCCESS;
        }

        $this->line('Найдено билетов: '.count($targets));
        $done = 0;
        $failed = 0;

        foreach ($targets as [$order, $ticket, $refund, $blanks]) {
            $numbers = implode(', ', array_column($blanks, 'number'));
            $this->line("Заказ {$order->id}, билет {$ticket->serial} {$ticket->number} (станция {$order->awtcod}): бланки {$numbers}");

            if ($dryRun) {
                continue;
            }

            try {
                $result = $salesApi->refund([
                    'awtcod' => $order->awtcod,
                    'operation' => 1,
                    'preview' => false,
                    // Рейс давно ушёл — без admin_mode станция откажет (IS_PRN).
                    'admin_mode' => true,
                    'tickets' => array_map(
                        fn (array $b) => ['serial' => $b['serial'], 'number' => $b['number']],
                        $blanks,
                    ),
                ])['data'];
            } catch (\Throwable $e) {
                $this->error('  не вышло: '.$e->getMessage());
                $failed++;

                continue;
            }

            foreach ($blanks as $blank) {
                Refund::create([
                    'order_id' => $order->id,
                    'kind' => Refund::KIND_BAGGAGE,
                    'baggage_count' => 1,
                    'serial' => $blank['serial'],
                    'number' => $blank['number'],
                    'place' => (int) $ticket->place,
                    'penalty_percent' => (int) $refund->penalty_percent,
                    'ticket_price' => 0,
                    'baggage_price' => (float) $ticket->price_bag,
                    // Ноль: деньги за багаж ушли покупателю вместе с билетом.
                    'amount' => 0,
                    'status' => Refund::STATUS_MONEY_REFUNDED,
                    'money_refunded_at' => $refund->money_refunded_at ?? $refund->created_at,
                    'fiscal_sign' => null,
                    'legacy' => [
                        'annulled_later' => true,
                        'reason' => 'Бланк аннулирован на станции отдельно: возврат билета прошёл до исправления'
                            .' (деньги за багаж возвращены в составе билета)',
                        'ticket_refund_id' => $refund->id,
                        'ticket_serial' => $ticket->serial,
                        'ticket_number' => $ticket->number,
                    ],
                ]);
            }

            $this->info('  аннулировано на станции, отмечено у нас (сумма 0, деньги уже возвращены ранее)');
            $this->line('  станция вернула по ведомости: '.($result['total_refund'] ?? '—'));
            $done++;
        }

        if ($dryRun) {
            $this->warn('Это был --dry-run, ничего не изменено.');

            return self::SUCCESS;
        }

        $this->info("Готово: обработано {$done}, с ошибкой {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Билеты, у которых есть возврат билета и остались неаннулированные
     * багажные бланки.
     *
     * @return array<int, array{0: Order, 1: Ticket, 2: Refund, 3: array<int, array{serial: string, number: string}>}>
     */
    private function targets(): array
    {
        /*
         * Возвраты СТАРОГО сайта пропускаем: там багаж аннулировался вместе
         * с билетом (legacy дописывал номера квитанций в I_N_TICKET), и
         * аннулировать их второй раз нечего — станция такой бланк уже
         * закрыла. Признак переноса — заполненный `legacy` у возврата.
         */
        $refunds = Refund::query()
            ->where('kind', Refund::KIND_TICKET)
            ->when(! $this->option('include-legacy'), fn ($q) => $q->whereNull('legacy'))
            ->when($this->option('order'), fn ($q, $id) => $q->where('order_id', $id))
            ->when($this->option('serial'), fn ($q, $v) => $q->where('serial', $v))
            ->when($this->option('number'), fn ($q, $v) => $q->where('number', $v))
            // Только билеты, у которых есть багажные квитанции: без этого
            // условия выборка — все возвраты за всю историю.
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('tickets')
                ->whereColumn('tickets.order_id', 'refunds.order_id')
                ->whereColumn('tickets.serial', 'refunds.serial')
                ->whereColumn('tickets.number', 'refunds.number')
                ->whereRaw("jsonb_array_length(coalesce(tickets.baggage_tickets, '[]'::jsonb)) > 0"))
            ->orderBy('created_at')
            ->get();

        $out = [];

        foreach ($refunds as $refund) {
            $order = Order::find($refund->order_id);

            if (! $order) {
                continue;
            }

            $ticket = $order->tickets->first(
                fn (Ticket $t) => (string) $t->serial === (string) $refund->serial
                    && (string) $t->number === (string) $refund->number,
            );

            if (! $ticket) {
                continue;
            }

            $returned = Refund::where('order_id', $order->id)
                ->where('kind', Refund::KIND_BAGGAGE)
                ->get()
                ->map(fn (Refund $r) => $r->serial.'/'.$r->number)
                ->all();

            $blanks = array_values(array_filter(
                $ticket->baggageBlanks(),
                fn (array $b) => ! in_array($b['serial'].'/'.$b['number'], $returned, true),
            ));

            if ($blanks === []) {
                continue;
            }

            $out[] = [$order, $ticket, $refund, $blanks];
        }

        return $out;
    }
}
