<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Onboarding\DebtPaymentService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessBankTransferDebtPaymentHandler
{
    public function __construct(
        private DebtPaymentService $debtPaymentService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProcessBankTransferDebtPaymentCommand $command): void
    {
        $now = $this->clock->now();
        $order = $command->order;

        if (!$order->hasUnpaidDebt()) {
            return;
        }

        $this->debtPaymentService->confirmDebtPaid($order, $now);
    }
}
