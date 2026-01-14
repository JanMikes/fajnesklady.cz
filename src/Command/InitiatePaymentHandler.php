<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GoPay\GoPayClient;
use App\Service\OrderService;
use App\Value\GoPayPayment;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InitiatePaymentHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private OrderService $orderService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(InitiatePaymentCommand $command): GoPayPayment
    {
        $order = $command->order;
        $now = $this->clock->now();

        // Mark order as awaiting payment
        $this->orderService->processPayment($order, $now);

        // Create payment in GoPay
        if ($order->isUnlimited()) {
            $payment = $this->goPayClient->createRecurringPayment(
                $order,
                $command->returnUrl,
                $command->notificationUrl,
            );
        } else {
            $payment = $this->goPayClient->createPayment(
                $order,
                $command->returnUrl,
                $command->notificationUrl,
            );
        }

        // Store GoPay payment ID on order
        $order->setGoPayPaymentId($payment->id);

        return $payment;
    }
}
