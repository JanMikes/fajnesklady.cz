<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\StorageCodeUsageType;
use App\Repository\PlaceStorageCodeUsageRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RemoveStorageCodeExclusionHandler
{
    public function __construct(
        private PlaceStorageCodeUsageRepository $usageRepository,
    ) {
    }

    public function __invoke(RemoveStorageCodeExclusionCommand $command): void
    {
        $usage = $this->usageRepository->find($command->usageId);

        if (null === $usage || StorageCodeUsageType::EXCLUDED !== $usage->type) {
            throw new \DomainException('Kód není vyloučen.');
        }

        // If the code was USED before being flipped to EXCLUDED, that
        // provenance is intentionally lost — the operator explicitly freed it.
        $this->usageRepository->remove($usage);
    }
}
