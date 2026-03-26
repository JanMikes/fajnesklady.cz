<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HandoverProtocolRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteLandlordHandoverHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CompleteLandlordHandoverCommand $command): void
    {
        $protocol = $this->handoverProtocolRepository->get($command->handoverProtocolId);
        $protocol->completeLandlordSide($command->comment, $command->newLockCode, $this->clock->now());
    }
}
