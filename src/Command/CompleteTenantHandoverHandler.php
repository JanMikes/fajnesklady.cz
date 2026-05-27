<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HandoverProtocolRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteTenantHandoverHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CompleteTenantHandoverCommand $command): void
    {
        $protocol = $this->handoverProtocolRepository->get($command->handoverProtocolId);
        $protocol->completeTenantSide($command->comment, $this->clock->now());

        $this->auditLogger->log(
            entityType: 'handover',
            entityId: $protocol->id->toRfc4122(),
            eventType: 'tenant_submitted',
            payload: ['status' => $protocol->status->value],
            orderId: $protocol->contract->order->id,
            userIdContext: $protocol->contract->user->id,
        );
    }
}
