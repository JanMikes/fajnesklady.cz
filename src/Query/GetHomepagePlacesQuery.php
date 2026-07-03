<?php

declare(strict_types=1);

namespace App\Query;

use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAvailabilityChecker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a constant 6 queries regardless of how many places / types / storages
 * exist: places, types (bulk), storages (bulk), and the 3 bulk overlap checks
 * inside {@see StorageAvailabilityChecker::availabilityForStorages()}. The
 * previous per-place/per-type/per-storage loops fired 70+ queries on fixture
 * data alone.
 */
#[AsMessageHandler]
final readonly class GetHomepagePlacesQuery
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private StorageTypeRepository $storageTypeRepository,
        private StorageRepository $storageRepository,
        private StorageAvailabilityChecker $availabilityChecker,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetHomepagePlaces $query): GetHomepagePlacesResult
    {
        $places = $this->placeRepository->findAllActive();
        if ([] === $places) {
            return new GetHomepagePlacesResult(places: []);
        }

        $startDate = $this->clock->now()->modify('tomorrow');
        $endDate = $startDate->modify('+30 days');

        $storageTypes = $this->storageTypeRepository->findPubliclyOrderableByPlaces($places);
        $storages = $this->storageRepository->findByStorageTypes($storageTypes);
        $availability = $this->availabilityChecker->availabilityForStorages($storages, $startDate, $endDate);

        // Count total/available storages per (place, type) pair. Grouping by the
        // storage's own place keeps parity with the old per-pair queries: a
        // storage whose place disagrees with its type's place matches no pair.
        /** @var array<string, int> $totalByPair */
        $totalByPair = [];
        /** @var array<string, int> $availableByPair */
        $availableByPair = [];
        foreach ($storages as $storage) {
            $pair = $storage->place->id->toRfc4122().'|'.$storage->storageType->id->toRfc4122();
            $totalByPair[$pair] = ($totalByPair[$pair] ?? 0) + 1;
            if ($availability[$storage->id->toRfc4122()]->isAvailable) {
                $availableByPair[$pair] = ($availableByPair[$pair] ?? 0) + 1;
            }
        }

        /** @var array<string, list<\App\Entity\StorageType>> $typesByPlace */
        $typesByPlace = [];
        foreach ($storageTypes as $type) {
            $typesByPlace[$type->place->id->toRfc4122()][] = $type;
        }

        $rows = [];
        /** @var array<string, array{0: float, 1: int}> $sortKeyByPlace ratio, total */
        $sortKeyByPlace = [];

        foreach ($places as $place) {
            $placeKey = $place->id->toRfc4122();
            $placeTotal = 0;
            $placeAvailable = 0;
            $lowestPrice = null;
            $lowestAreaM2 = null;
            $typeRows = [];

            foreach ($typesByPlace[$placeKey] ?? [] as $type) {
                $pair = $placeKey.'|'.$type->id->toRfc4122();
                $availableOfType = $availableByPair[$pair] ?? 0;

                $placeTotal += $totalByPair[$pair] ?? 0;
                $placeAvailable += $availableOfType;

                $priceCzk = $type->getDefaultPricePerMonthLongTermInCzk();
                $areaM2 = $type->getFloorAreaInSquareMeters();
                $lowestPrice = null === $lowestPrice ? $priceCzk : min($lowestPrice, $priceCzk);
                $lowestAreaM2 = null === $lowestAreaM2 ? $areaM2 : min($lowestAreaM2, $areaM2);

                $typeRows[] = new GetHomepagePlaceTypeRow(
                    storageType: $type,
                    isAvailable: $availableOfType > 0,
                );
            }

            $rows[] = new GetHomepagePlaceRow(
                place: $place,
                storageTypes: $typeRows,
                lowestPrice: $lowestPrice,
                lowestAreaM2: null !== $lowestAreaM2 ? round($lowestAreaM2, 1) : null,
                isAvailable: $placeAvailable > 0,
            );
            $sortKeyByPlace[$placeKey] = [$placeTotal > 0 ? $placeAvailable / $placeTotal : 0.0, $placeTotal];
        }

        // Availability ratio DESC, storage count DESC, name ASC.
        usort(
            $rows,
            static function (GetHomepagePlaceRow $a, GetHomepagePlaceRow $b) use ($sortKeyByPlace): int {
                [$ratioA, $totalA] = $sortKeyByPlace[$a->place->id->toRfc4122()];
                [$ratioB, $totalB] = $sortKeyByPlace[$b->place->id->toRfc4122()];

                return [$ratioB, $totalB, $a->place->name] <=> [$ratioA, $totalA, $b->place->name];
            },
        );

        return new GetHomepagePlacesResult(places: $rows);
    }
}
