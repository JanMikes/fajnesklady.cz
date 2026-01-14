<?php

declare(strict_types=1);

namespace App\Service\GoPay;

use App\Entity\Order;
use App\Value\GoPayPayment;
use App\Value\GoPayPaymentStatus;
use GoPay\Definition\Language;
use GoPay\Definition\Payment\Currency;
use GoPay\Definition\Payment\Recurrence;
use GoPay\Http\Response;
use GoPay\Payments;

final readonly class GoPayApiClient implements GoPayClient
{
    public function __construct(
        private Payments $gopay,
    ) {
    }

    public function createPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment
    {
        $response = $this->gopay->createPayment($this->buildPaymentData($order, $returnUrl, $notificationUrl));

        $this->assertSuccess($response);

        return new GoPayPayment(
            id: (int) $response->json['id'],
            gwUrl: $response->json['gw_url'],
            state: $response->json['state'],
        );
    }

    public function createRecurringPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment
    {
        $paymentData = $this->buildPaymentData($order, $returnUrl, $notificationUrl);
        $paymentData['recurrence'] = [
            'recurrence_cycle' => Recurrence::ON_DEMAND,
            'recurrence_date_to' => '2099-12-31',
        ];

        $response = $this->gopay->createPayment($paymentData);

        $this->assertSuccess($response);

        return new GoPayPayment(
            id: (int) $response->json['id'],
            gwUrl: $response->json['gw_url'],
            state: $response->json['state'],
        );
    }

    public function createRecurrence(int $parentPaymentId, int $amount, string $orderNumber, string $description): GoPayPayment
    {
        $response = $this->gopay->createRecurrence($parentPaymentId, [
            'amount' => $amount,
            'currency' => Currency::CZECH_CROWNS,
            'order_number' => $orderNumber,
            'order_description' => $description,
        ]);

        $this->assertSuccess($response);

        return new GoPayPayment(
            id: (int) $response->json['id'],
            gwUrl: $response->json['gw_url'] ?? '',
            state: $response->json['state'],
        );
    }

    public function voidRecurrence(int $paymentId): void
    {
        $response = $this->gopay->voidRecurrence($paymentId);
        $this->assertSuccess($response);
    }

    public function getStatus(int $paymentId): GoPayPaymentStatus
    {
        $response = $this->gopay->getStatus($paymentId);
        $this->assertSuccess($response);

        return new GoPayPaymentStatus(
            id: (int) $response->json['id'],
            state: $response->json['state'],
            parentId: $response->json['parent_id'] ?? null,
        );
    }

    public function getEmbedJsUrl(): string
    {
        return $this->gopay->urlToEmbedJs();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentData(Order $order, string $returnUrl, string $notificationUrl): array
    {
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storageType->place;

        return [
            'payer' => [
                'contact' => [
                    'email' => $order->user->email,
                ],
            ],
            'amount' => $order->totalPrice,
            'currency' => Currency::CZECH_CROWNS,
            'order_number' => $order->id->toRfc4122(),
            'order_description' => sprintf(
                'Pronájem skladu %s - %s (%s)',
                $storage->number,
                $storageType->name,
                $place->name,
            ),
            'lang' => Language::CZECH,
            'callback' => [
                'return_url' => $returnUrl,
                'notification_url' => $notificationUrl,
            ],
        ];
    }

    private function assertSuccess(Response $response): void
    {
        if (!$response->hasSucceed()) {
            throw new GoPayException(
                sprintf('GoPay API error: %s', json_encode($response->json, JSON_THROW_ON_ERROR)),
                (int) $response->statusCode,
            );
        }
    }
}
