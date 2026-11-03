<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Repository\HandoverProtocolRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The handover cron opens a protocol 7 days before the contract ends. When the
 * tenant then prolongs, that protocol is premature: left alone it keeps
 * e-mailing "hand over your unit" reminders to someone who is staying, and it
 * blocks the cron from opening a fresh one at the real end (it skips contracts
 * that already have a protocol).
 *
 * An untouched protocol carries no information, so it is discarded — the cron
 * recreates it once the new end date enters the window. A protocol somebody
 * already started (comment, skip, photos) is kept as evidence; the reminder
 * query and the lock-code guard both ignore it until it is inside the window
 * again, and the admin gets an audit entry + log warning to review it.
 */
#[AsMessageHandler]
final readonly class DiscardPrematureHandoverOnContractProlongedHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private HandoverProtocolRepository $handoverProtocolRepository,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ContractProlonged $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $protocol = $this->handoverProtocolRepository->findByContract($contract);

        if (null === $protocol || $protocol->isFullyCompleted()) {
            return;
        }

        // Prolonged by only a few days — the handover is still due soon, the
        // open protocol stays relevant.
        if ($contract->isWithinHandoverWindow($this->clock->now())) {
            return;
        }

        $payload = [
            'contract_id' => $contract->id->toRfc4122(),
            'previous_end_date' => $event->previousEndDate->format('Y-m-d'),
            'new_end_date' => $event->newEndDate->format('Y-m-d'),
            'status' => $protocol->status->value,
            'reminders_sent' => $protocol->remindersSentCount,
        ];

        if ($protocol->isUntouched()) {
            $this->handoverProtocolRepository->remove($protocol);

            $this->auditLogger->log(
                entityType: 'handover',
                entityId: $protocol->id->toRfc4122(),
                eventType: 'discarded_on_prolongation',
                payload: $payload,
                orderId: $contract->order->id,
                userIdContext: $contract->user->id,
            );

            return;
        }

        $this->logger->warning('Contract prolonged while its handover protocol is partially filled — protocol kept, review it', [
            'handover_protocol_id' => $protocol->id->toRfc4122(),
            'contract_id' => $contract->id->toRfc4122(),
        ]);

        $this->auditLogger->log(
            entityType: 'handover',
            entityId: $protocol->id->toRfc4122(),
            eventType: 'kept_on_prolongation',
            payload: $payload,
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }
}
