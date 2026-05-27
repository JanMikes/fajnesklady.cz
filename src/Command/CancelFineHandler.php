<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Fine;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CancelFineHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CancelFineCommand $command): void
    {
        $fine = $this->entityManager->find(Fine::class, $command->fineId);
        if (null === $fine) {
            throw new \DomainException('Fine not found.');
        }

        $admin = $this->entityManager->find(User::class, $command->cancelledById);
        if (null === $admin) {
            throw new \DomainException('Admin user not found.');
        }

        if (!$fine->isPayable()) {
            throw new \DomainException('Fine cannot be cancelled.');
        }

        $fine->cancel($admin, $this->clock->now());

        $this->auditLogger->log(
            entityType: 'fine',
            entityId: $command->fineId->toRfc4122(),
            eventType: 'cancelled',
            payload: [
                'cancelled_by' => $admin->id->toRfc4122(),
            ],
            orderId: $fine->contract->order->id,
            userIdContext: $fine->user->id,
        );
    }
}
