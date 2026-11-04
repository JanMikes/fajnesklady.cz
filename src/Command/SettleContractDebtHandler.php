<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SettleContractDebtHandler
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private ProvideIdentity $identityProvider,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SettleContractDebtCommand $command): void
    {
        $contract = $command->contract;

        if (!$contract->isTerminated() || !$contract->hasOutstandingDebt()) {
            throw new \DomainException('Contract has no post-termination debt to settle.');
        }

        $now = $this->clock->now();
        $contract->reduceOutstandingDebt($command->amountInHaler);

        // Money genuinely arrived off-system, so record it as a real payment —
        // keeps the landlord's self-billing / commission tally correct.
        $this->paymentRepository->save(new Payment(
            id: $this->identityProvider->next(),
            order: null,
            contract: $contract,
            storage: $contract->storage,
            amount: $command->amountInHaler,
            paidAt: $now,
            createdAt: $now,
        ));

        // No invoice: off-system money has no payment evidence in the
        // platform, and we never issue a tax document without one.

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'debt_settled',
            payload: [
                'amount' => $command->amountInHaler,
                'remaining' => $contract->outstandingDebtAmount ?? 0,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }
}
