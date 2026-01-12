<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\PlaceNotFoundException;
use App\Repository\PlaceRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeletePlaceHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
    ) {
    }

    public function __invoke(DeletePlaceCommand $command): void
    {
        $place = $this->placeRepository->findById($command->placeId);
        if (null === $place) {
            throw PlaceNotFoundException::withId($command->placeId);
        }

        $this->placeRepository->delete($place);
    }
}
