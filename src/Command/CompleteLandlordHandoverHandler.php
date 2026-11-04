<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Exception\UserNotFound;
use App\Repository\HandoverProtocolRepository;
use App\Service\AuditLogger;
use App\Service\StorageCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteLandlordHandoverHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private AuditLogger $auditLogger,
        private StorageCodeGenerator $codeGenerator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CompleteLandlordHandoverCommand $command): void
    {
        $protocol = $this->handoverProtocolRepository->get($command->handoverProtocolId);

        $storage = $protocol->contract->storage;
        $place = $storage->getPlace();

        if ($place->storageCodesEnabled && null !== $command->newLockCode && '' !== $command->newLockCode) {
            $this->codeGenerator->validateForStorage($place, $storage, $command->newLockCode);
        }

        $now = $this->clock->now();
        $protocol->completeLandlordSide($command->comment, $command->newLockCode, $now);

        $this->auditLogger->log(
            entityType: 'handover',
            entityId: $protocol->id->toRfc4122(),
            eventType: 'landlord_submitted',
            payload: ['status' => $protocol->status->value],
            orderId: $protocol->contract->order->id,
            userIdContext: $protocol->contract->user->id,
        );

        // The tenant may have filled their side between the page render and
        // this submit — then there is nothing left to resolve.
        if (null !== $command->resolveTenantSideById && $protocol->needsTenantCompletion()) {
            $resolvedBy = $this->entityManager->find(User::class, $command->resolveTenantSideById)
                ?? throw UserNotFound::withId($command->resolveTenantSideById);

            // Landlord side is already in, so this completes the protocol and
            // records the single HandoverCompleted event (release, lock code, e-mails).
            $protocol->skipTenantSide($resolvedBy, $now);

            $this->auditLogger->log(
                entityType: 'handover',
                entityId: $protocol->id->toRfc4122(),
                eventType: 'tenant_skipped',
                payload: [
                    'status' => $protocol->status->value,
                    'skipped_by' => $command->resolveTenantSideById->toRfc4122(),
                    'via' => 'landlord_form',
                ],
                orderId: $protocol->contract->order->id,
                userIdContext: $protocol->contract->user->id,
            );
        }
    }
}
