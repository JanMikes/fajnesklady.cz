<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HandoverProtocolRepository;
use App\Service\StorageCodeGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteLandlordHandoverHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private StorageCodeGenerator $codeGenerator,
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

        $protocol->completeLandlordSide($command->comment, $command->newLockCode, $this->clock->now());
    }
}
