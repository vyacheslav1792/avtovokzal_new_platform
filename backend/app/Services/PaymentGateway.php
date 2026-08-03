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
}
