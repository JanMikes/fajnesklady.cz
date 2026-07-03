<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Exception\UserNotFound;
use App\Repository\HandoverProtocolRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SkipTenantHandoverHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SkipTenantHandoverCommand $command): void
    {
        $protocol = $this->handoverProtocolRepository->get($command->handoverProtocolId);

        $skippedBy = $this->entityManager->find(User::class, $command->skippedById)
            ?? throw UserNotFound::withId($command->skippedById);

        $protocol->skipTenantSide($skippedBy, $this->clock->now());

        $this->auditLogger->log(
            entityType: 'handover',
            entityId: $protocol->id->toRfc4122(),
            eventType: 'tenant_skipped',
            payload: ['status' => $protocol->status->value, 'skipped_by' => $command->skippedById->toRfc4122()],
            orderId: $protocol->contract->order->id,
            userIdContext: $protocol->contract->user->id,
        );
    }
}
