<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminRefundService;
use App\Services\AisEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Админский возврат билета (перенос legacy ais/back_bilet.php).
 * Защитный код 123321 из legacy сознательно не перенесён (решение
 * пользователя): его заменили авторизация сотрудника АИС и личный код
 * подтверждения — в отличие от одного кода на всех, он у каждого свой
 * и видно, кто вернул билет.
 *
 * Права: `back-ticket.inspect` на поиск билета, `back-ticket.refund` на
 * возврат и `back-ticket.repair` на починку зависшего возврата денег;
 * последние два требуют кода подтверждения (middleware `ais.can`).
 */
class AdminRefundController extends Controller
{
    public function __construct(private readonly AdminRefundService $refunds) {}

    /** Карточка билета: сверка со шлюзом, состояние, варианты процентов. */
    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'serial' => ['nullable', 'string', 'max:20'],
            'number' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->inspect(
            $data['order_id'],
            $data['serial'] ?? null,
            $data['number'] ?? null,
        )]);
    }

    /** Выполнить возврат с выбранным процентом удержаний. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'serial' => ['required', 'string', 'max:20'],
            'number' => ['required', 'string', 'max:20'],
            'percent' => ['required', 'integer'],
            /*
             * Возврат при недоступной базе станции: деньги покупателю
             * возвращаем сразу, аннулирование бланка досылаем позже
             * (кнопка «Дослать аннулирование»). Сервер сам проверит, что
             * станция действительно не отвечает.
             */
            'without_station' => ['sometimes', 'boolean'],
            /*
             * Возврат БЕЗ фискального чека. Обычно чек обязателен (54-ФЗ),
             * но бывает, что он уже пробит вручную на кассе — тогда второй
             * не нужен. Пробить его позже можно кнопкой в карточке.
             */
            'without_receipt' => ['sometimes', 'boolean'],
            /*
             * Бланк на станции УЖЕ аннулирован, а деньги покупателю не
             * возвращены: аннулировать нечего, нужен только возврат денег
             * и запись о нём. Сервер сам сверяется с журналом станции —
             * этой кнопкой обычный путь не обойти.
             */
            'already_annulled' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->confirm(
            $data['order_id'],
            $data['serial'],
            $data['number'],
            (int) $data['percent'],
            (bool) ($data['without_station'] ?? false),
            withReceipt: ! (bool) ($data['without_receipt'] ?? false),
            alreadyAnnulled: (bool) ($data['already_annulled'] ?? false),
        )]);
    }

    /**
     * Дослать на станцию аннулирование по возврату, сделанному без неё.
     * Право то же, что у возврата денег: операция меняет ведомость станции.
     */
    public function annul(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'refund_id' => ['required', 'uuid'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->annulAtStation(
            $data['order_id'],
            $data['refund_id'],
        )]);
    }

    /**
     * Отменить платёж по заказу, у которого билетов нет (дооформление не
     * состоялось, а деньги списаны). Делает то же, что автоматика
     * брошенных покупок, но по кнопке кассира — не дожидаясь её запуска.
     */
    public function cancelPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
        ]);

        $employee = $request->user();

        return response()->json(['success' => true, 'data' => $this->refunds->cancelPayment(
            $data['order_id'],
            $employee instanceof AisEmployee
                ? 'вручную, '.($employee->fullName ?: $employee->login)
                : 'вручную',
        )]);
    }

    /**
     * Пробить фискальный чек ПОКУПКИ вручную (он один на заказ).
     * Чек продажи мог не пробиться при оформлении — ККТ без связи,
     * закрытая смена, не запущен воркер очереди.
     */
    public function saleReceipt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->registerSaleReceipt(
            $data['order_id'],
        )]);
    }

    /** Возвраты, ждущие аннулирования на станции (раздел АИС). */
    public function pending(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['items' => $this->refunds->pendingAnnulments()],
        ]);
    }

    /**
     * Вернуть только багаж: билет остаётся действующим.
     * На станции аннулируются отдельные багажные бланки (категория 9).
     */
    public function confirmBaggage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'serial' => ['required', 'string', 'max:20'],
            'number' => ['required', 'string', 'max:20'],
            'count' => ['required', 'integer', 'min:1', 'max:5'],
            'percent' => ['required', 'integer'],
            'without_receipt' => ['sometimes', 'boolean'],
        ], [
            'count.required' => 'Укажите, сколько мест багажа вернуть.',
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->confirmBaggage(
            $data['order_id'],
            $data['serial'],
            $data['number'],
            (int) $data['count'],
            (int) $data['percent'],
            withReceipt: ! (bool) ($data['without_receipt'] ?? false),
        )]);
    }

    /**
     * Вернуть покупателю удержание по уже сделанному возврату.
     * Станция об этом возврате не знает — только деньги и наша запись.
     */
    public function confirmPenalty(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'refund_id' => ['required', 'uuid'],
            // Часть удержания: без суммы отдаётся весь остаток.
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'without_receipt' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->confirmPenalty(
            $data['order_id'],
            $data['refund_id'],
            isset($data['amount']) ? (float) $data['amount'] : null,
            withReceipt: ! (bool) ($data['without_receipt'] ?? false),
        )]);
    }

    /**
     * Повторить зависший возврат денег по банку.
     *
     * Либо по номеру билета (как legacy), либо по `refund_id` — зависнуть
     * может и возврат удержания, и возврат багажа, у них свои обращения к
     * шлюзу и своего номера билета у багажной квитанции нет.
     */
    public function repair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'refund_id' => ['sometimes', 'uuid'],
            'serial' => ['required_without:refund_id', 'nullable', 'string', 'max:20'],
            'number' => ['required_without:refund_id', 'nullable', 'string', 'max:20'],
            'without_receipt' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->repairMoney(
            $data['order_id'],
            $data['serial'] ?? null,
            $data['number'] ?? null,
            $data['refund_id'] ?? null,
            withReceipt: ! (bool) ($data['without_receipt'] ?? false),
        )]);
    }

    /**
     * Пробить фискальный чек по операции возврата вручную.
     *
     * Чек ставится в очередь `receipts`, воркер которой запускается не
     * всегда, да и ККТ может отказать (закрытая смена, нет связи) — тогда
     * повторять должен человек, видя результат сразу. Операция
     * идемпотентна: KKM API не пробьёт второй чек по тому же ключу.
     */
    public function receipt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:36'],
            'refund_id' => ['required', 'uuid'],
        ]);

        return response()->json(['success' => true, 'data' => $this->refunds->registerReceipt(
            $data['order_id'],
            $data['refund_id'],
        )]);
    }
}
