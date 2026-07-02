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

        // Spec 076: cards only establish recurring monthly payments. Every
        // other billing mode is paid by bank transfer and never reaches this
        // endpoint (the payment page renders a QR code, no GoPay JS).
        if (BillingMode::AUTO_RECURRING !== $order->billingMode) {
            throw new \LogicException('Card payments are recurring-only; non-recurring orders are paid by bank transfer.');
        }

        $payment = $this->goPayClient->createRecurringPayment(
            $order,
            $command->returnUrl,
            $command->notificationUrl,
        );

        $order->setGoPayPaymentId($payment->id);

        return $payment;
    }
}
