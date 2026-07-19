<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\InvoicingService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SettleContractDebtHandler
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private ProvideIdentity $identityProvider,
        private InvoicingService $invoicingService,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
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

        if ($command->issueInvoice) {
            try {
                $this->invoicingService->issueInvoiceForRecurringPayment($contract, $command->amountInHaler, $now);
            } catch (\Throwable $e) {
                // Best-effort: a Fakturoid outage must not roll back the
                // recorded settlement. The admin can re-issue in Fakturoid.
                $this->logger->error('Debt-settlement invoice failed', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }
        }

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'debt_settled',
            payload: [
                'amount' => $command->amountInHaler,
                'remaining' => $contract->outstandingDebtAmount ?? 0,
                'invoice_issued' => $command->issueInvoice,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }
}
