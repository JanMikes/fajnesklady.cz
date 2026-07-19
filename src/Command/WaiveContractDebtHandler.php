<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class WaiveContractDebtHandler
{
    public function __construct(
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(WaiveContractDebtCommand $command): void
    {
        $contract = $command->contract;

        if (!$contract->isTerminated() || !$contract->hasOutstandingDebt()) {
            throw new \DomainException('Contract has no post-termination debt to waive.');
        }

        // Write-off: no money moved, so NO Payment row is recorded — only the
        // debt is reduced/cleared and the decision is audited.
        $contract->reduceOutstandingDebt($command->amountInHaler);

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'debt_waived',
            payload: [
                'amount' => $command->amountInHaler,
                'remaining' => $contract->outstandingDebtAmount ?? 0,
                'reason' => $command->reason,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }
}
