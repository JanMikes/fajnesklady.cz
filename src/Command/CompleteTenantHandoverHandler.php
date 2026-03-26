<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HandoverProtocolRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteTenantHandoverHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CompleteTenantHandoverCommand $command): void
    {
        $protocol = $this->handoverProtocolRepository->get($command->handoverProtocolId);
        $protocol->completeTenantSide($command->comment, $this->clock->now());
    }
}
