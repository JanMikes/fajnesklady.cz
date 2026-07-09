<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ManualPaymentRequestRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExtendPaymentDeadlineHandler
{
    public function __construct(
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExtendPaymentDeadlineCommand $command): void
    {
        $now = $this->clock->now();
        $contract = $command->contract;
        $previousAnchor = $contract->effectiveDunningAnchor();

        $contract->extendPaymentDeadline($command->newDeadline, $now);

        // Re-open the current cycle's request so the fresh post-grace ladder
        // can re-fire on the same period row (its sentStages would otherwise
        // silence every already-sent stage). No-op / absent on non-manual
        // tracks or before the first request was sent.
        if ($contract->usesManualBillingTrack() && null !== $contract->nextBillingDate) {
            $request = $this->manualPaymentRequestRepository->findUnpaidByContractAndPeriod(
                $contract,
                $contract->nextBillingDate,
            );
            $request?->reopenForExtension();
        }

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'payment_deadline_extended',
            payload: [
                'new_deadline' => $command->newDeadline->format('Y-m-d'),
                'previous_anchor' => $previousAnchor?->format('Y-m-d'),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }
}
