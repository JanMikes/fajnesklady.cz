<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlaceStorageCodeUsage;
use App\Enum\StorageCodeUsageType;
use App\Exception\InvalidStorageCode;
use App\Repository\PlaceRepository;
use App\Repository\PlaceStorageCodeUsageRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExcludeStorageCodesHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private PlaceStorageCodeUsageRepository $usageRepository,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int number of codes newly excluded (created or flipped from USED)
     */
    public function __invoke(ExcludeStorageCodesCommand $command): int
    {
        $place = $this->placeRepository->get($command->placeId);
        $now = $this->clock->now();
        $count = 0;

        foreach ($command->codes as $code) {
            // Range deliberately NOT enforced: an out-of-range system code
            // can't be assigned today, but excluding it protects against a
            // future range widening.
            if (strlen($code) !== $place->storageCodeDigits) {
                throw InvalidStorageCode::wrongLength($place->storageCodeDigits);
            }

            if (!ctype_digit($code)) {
                throw InvalidStorageCode::notNumeric();
            }

            $existing = $this->usageRepository->findOneByPlaceAndCode($place, $code);

            if (null !== $existing) {
                if (StorageCodeUsageType::EXCLUDED === $existing->type) {
                    continue;
                }

                $existing->exclude($command->note);
                ++$count;

                continue;
            }

            $this->usageRepository->save(new PlaceStorageCodeUsage(
                id: $this->identityProvider->next(),
                place: $place,
                code: $code,
                type: StorageCodeUsageType::EXCLUDED,
                note: $command->note,
                usedAt: $now,
            ));
            ++$count;
        }

        return $count;
    }
}
