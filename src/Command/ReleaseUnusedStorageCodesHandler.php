<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlaceRepository;
use App\Repository\PlaceStorageCodeUsageRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReleaseUnusedStorageCodesHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private PlaceStorageCodeUsageRepository $usageRepository,
    ) {
    }

    public function __invoke(ReleaseUnusedStorageCodesCommand $command): int
    {
        $place = $this->placeRepository->get($command->placeId);

        return $this->usageRepository->releaseUnusedForPlace($place);
    }
}
