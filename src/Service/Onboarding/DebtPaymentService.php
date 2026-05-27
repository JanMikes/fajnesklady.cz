<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Command\CompleteOrderCommand;
use App\Entity\Order;
use App\Service\AuditLogger;
use App\Service\OrderService;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DebtPaymentService
{
    public function __construct(
        private OrderService $orderService,
        private AuditLogger $auditLogger,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function confirmDebtPaid(Order $order, \DateTimeImmutable $now, ?string $goPayPaymentId = null): void
    {
        $order->markDebtPaid($now);

        $this->auditLogger->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'debt_payment_confirmed',
            payload: [
                'debt_amount' => $order->onboardingDebtInHaler,
                'gopay_payment_id' => $goPayPaymentId,
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );

        $isFreeOrPrepaid = 0 === $order->individualMonthlyAmount
            || null !== $order->paidThroughDate;

        if ($isFreeOrPrepaid && $order->canBePaid()) {
            $this->orderService->confirmPayment($order, $now, 0);
            if ($order->hasAcceptedTerms()) {
                $this->commandBus->dispatch(new CompleteOrderCommand($order));
            }
        }
    }
}
