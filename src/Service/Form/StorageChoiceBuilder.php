<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Storage;
use App\Repository\StorageRepository;

final readonly class StorageChoiceBuilder
{
    public function __construct(
        private StorageRepository $storageRepository,
    ) {
    }

    /**
     * Build choices for the admin onboarding storage picker — only available units,
     * grouped by "{Place name} — {Storage type name}" with natural-sorted numbers.
     *
     * @return array<string, array<string, string>>
     */
    public function buildAvailableGroupedChoices(): array
    {
        return $this->groupAndSort($this->storageRepository->findAllAvailable());
    }

    /**
     * @param Storage[] $storages
     *
     * @return array<string, array<string, string>>
     */
    public function groupAndSort(array $storages): array
    {
        $grouped = [];
        foreach ($storages as $storage) {
            $groupLabel = $storage->place->name.' — '.$storage->storageType->name;
            $grouped[$groupLabel][$storage->number] = $storage->id->toRfc4122();
        }

        uksort($grouped, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        foreach ($grouped as $groupLabel => $opts) {
            uksort($opts, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
            $grouped[$groupLabel] = $opts;
        }

        return $grouped;
    }
}
