<?php

namespace App\Services;

use Voronkovich\SberbankAcquiring\Client;
use Voronkovich\SberbankAcquiring\OrderStatus;

/**
 * Оплаты через Сбербанк-эквайринг (SDK voronkovich/sberbank-acquiring-client).
 * Локально работает тестовый контур (3dsec.sberbank.ru, metagraphica).
 */
class PaymentGateway
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'userName' => config('services.sberbank.username'),
            'password' => config('services.sberbank.password'),
            'apiUri' => config('services.sberbank.api_uri'),
            'language' => 'ru',
            'currency' => 643,
        ]);
    }

    /**
     * Регистрация заказа в шлюзе.
     *
     * @param  int  $amountKopecks  сумма в копейках
     * @return array{orderId: string, formUrl: string}
     */
    public function register(string $orderNumber, int $amountKopecks, string $description, ?string $phone = null): array
    {
        // 128 символов — предел поля description у шлюза; режем ровно как
        // legacy (`mb_substr($desctiption_sbrf, 0, 128)` в passengers.php),
        // иначе боевой шлюз вернёт ошибку на длинном описании.
        $extra = ['description' => mb_substr($description, 0, 128)];

        if ($phone) {
            $extra['phone'] = $phone;
        }

        return $this->client->registerOrder(
            $orderNumber,
            $amountKopecks,
            config('services.sberbank.return_url'),
            $extra,
        );
    }

    /**
     * Развёрнутый статус заказа в шлюзе (getOrderStatusExtended):
     * orderStatus, amount, paymentAmountInfo.refundedAmount,
     * actionCodeDescription — сверка оплаты перед админским возвратом
     * (legacy back_bilet.php).
     */
    public function statusExtended(string $sberOrderId): array
    {
        // В SDK метод называется getOrderStatus, но внутри дергает
        // getOrderStatusExtended.do — тот же вызов, что в legacy.
        return $this->client->getOrderStatus($sberOrderId);
    }

    /** Оплачен ли заказ (orderStatus == DEPOSITED). */
    public function isPaid(string $sberOrderId): bool
    {
        $status = $this->client->getOrderStatus($sberOrderId);

        return (int) ($status['orderStatus'] ?? -1) === OrderStatus::DEPOSITED;
    }

    /** Отмена неоплаченного/ошибочного заказа. */
    public function reverse(string $sberOrderId): array
    {
        return $this->client->reverseOrder($sberOrderId);
    }

    /**
     * Частичный возврат платежа (возврат билета).
     *
     * @param  int  $amountKopecks  сумма возврата в копейках
     *
     * @throws \Voronkovich\SberbankAcquiring\Exception\ActionException при отказе шлюза
     */
    public function refund(string $sberOrderId, int $amountKopecks): array
    {
        return $this->client->refundOrder($sberOrderId, $amountKopecks);
    }
}
