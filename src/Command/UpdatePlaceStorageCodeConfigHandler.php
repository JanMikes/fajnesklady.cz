<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlaceRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdatePlaceStorageCodeConfigHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdatePlaceStorageCodeConfigCommand $command): void
    {
        $place = $this->placeRepository->get($command->placeId);
        $place->updateStorageCodeConfig(
            enabled: $command->enabled,
            digits: $command->digits,
            from: $command->from,
            to: $command->to,
            now: $this->clock->now(),
        );
    }
}
