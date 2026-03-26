<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReleaseStorageOnHandoverCompletedHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(HandoverCompleted $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $storage = $contract->storage;
        $now = $this->clock->now();

        if (null !== $event->newLockCode) {
            $storage->updateLockCode($event->newLockCode, $now);
        }

        if ($contract->isTerminated() && $storage->isOccupied()) {
            $storage->release($now);
            $this->auditLogger->logStorageReleased($storage, 'Handover protocol completed');
        }
    }
}
