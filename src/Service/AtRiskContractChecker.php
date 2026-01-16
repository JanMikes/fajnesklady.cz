<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\StorageType;
use App\Repository\ContractRepository;

/**
 * Service for identifying contracts that are "at risk" when storage availability decreases.
 *
 * A contract is considered "at risk" when:
 * - It is a LIMITED contract (has an end date)
 * - At the contract's end date, only the contract holder's storage would be available
 * - This means if they don't extend, someone else might book their storage
 */
readonly class AtRiskContractChecker
{
    public function __construct(
        private ContractRepository $contractRepository,
        private StorageAssignment $storageAssignment,
    ) {
    }

    /**
     * Find LIMITED contracts where the user would have no alternative
     * storage available when their contract ends.
     *
     * @return Contract[]
     */
    public function findAtRiskContracts(StorageType $storageType, \DateTimeImmutable $now): array
    {
        $limitedContracts = $this->contractRepository->findLimitedByStorageType($storageType, $now);

        $atRiskContracts = [];

        foreach ($limitedContracts as $contract) {
            $endDate = $contract->endDate;

            if (null === $endDate) {
                continue;
            }

            // Get the place from the contract's storage
            $place = $contract->storage->place;

            // Count how many storages would be available AFTER the contract ends
            // (endDate is inclusive, so we check the day after)
            // If only 1 is available, it's the contract holder's own storage becoming free
            $dayAfterEnd = $endDate->modify('+1 day');
            $availableCount = $this->storageAssignment->countAvailableStorages(
                $storageType,
                $place,
                $dayAfterEnd,
                $dayAfterEnd,
            );

            if (1 === $availableCount) {
                $atRiskContracts[] = $contract;
            }
        }

        return $atRiskContracts;
    }
}
