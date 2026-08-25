<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogger;
use App\Service\Onboarding\DebtPaymentService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SettleOnboardingDebtHandler
{
    public function __construct(
        private DebtPaymentService $debtPaymentService,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SettleOnboardingDebtCommand $command): void
    {
        $order = $command->order;

        if (!$order->hasUnpaidDebt()) {
            throw new \DomainException('Order has no unpaid onboarding debt to settle.');
        }

        $now = $this->clock->now();

        // Record the admin's action first: confirmDebtPaid() may dispatch
        // CompleteOrderCommand nested, and our own writes stay ahead of it.
        $this->auditLogger->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'onboarding_debt_settled',
            payload: [
                'amount' => $order->onboardingDebtInHaler,
                'source' => 'admin_external',
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );

        // Same path the GoPay webhook and the FIO cron take: marks the debt
        // paid, records OnboardingDebtPaid (→ Fakturoid debt invoice + receipt
        // e-mail to the customer, admin notification) and auto-completes a
        // free / externally prepaid onboarding order whose only blocker was
        // this debt.
        $this->debtPaymentService->confirmDebtPaid($order, $now);
    }
}
