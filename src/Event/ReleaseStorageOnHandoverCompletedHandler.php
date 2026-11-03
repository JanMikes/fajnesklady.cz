<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\AuditLogger;
use App\Service\StorageCodeGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReleaseStorageOnHandoverCompletedHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private AuditLogger $auditLogger,
        private StorageCodeGenerator $codeGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HandoverCompleted $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $storage = $contract->storage;
        $now = $this->clock->now();

        if (null !== $event->newLockCode) {
            if ($contract->isWithinHandoverWindow($now)) {
                $this->codeGenerator->applyCode($storage, $event->newLockCode, $now);
            } else {
                // A protocol completed while the rental still runs on (e.g. a
                // prolonged contract) must never re-key the unit — that would
                // lock a paying tenant out of their own storage.
                $this->logger->warning('Handover completed outside the handover window — new lock code NOT applied', [
                    'handover_protocol_id' => $event->handoverProtocolId->toRfc4122(),
                    'contract_id' => $contract->id->toRfc4122(),
                ]);
            }
        }

        if ($contract->isTerminated() && $storage->isOccupied()) {
            $storage->release($now);
            $this->auditLogger->logStorageReleased($storage, 'Handover protocol completed');
        }
    }
}
