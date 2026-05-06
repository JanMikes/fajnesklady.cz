<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlaceRepository;
use App\Service\StorageCodeGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BulkGenerateStorageCodesHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private StorageCodeGenerator $codeGenerator,
    ) {
    }

    public function __invoke(BulkGenerateStorageCodesCommand $command): int
    {
        $place = $this->placeRepository->get($command->placeId);

        return count($this->codeGenerator->bulkGenerateForEmpty($place));
    }
}
