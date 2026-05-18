<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\BillingMode;
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

        $this->orderService->processPayment($order, $now);

        // Branch on Order.billingMode — AUTO sets up a GoPay ON_DEMAND token,
        // ONE_TIME and MANUAL_RECURRING take the same one-shot path because the
        // customer is paying just the first month in both cases.
        $payment = match ($order->billingMode) {
            BillingMode::AUTO_RECURRING => $this->goPayClient->createRecurringPayment(
                $order,
                $command->returnUrl,
                $command->notificationUrl,
            ),
            BillingMode::ONE_TIME, BillingMode::MANUAL_RECURRING => $this->goPayClient->createPayment(
                $order,
                $command->returnUrl,
                $command->notificationUrl,
            ),
        };

        $order->setGoPayPaymentId($payment->id);

        return $payment;
    }
}
