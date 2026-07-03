<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Query\GetHomepagePlaceRow;
use App\Query\GetHomepagePlaces;
use App\Query\GetHomepagePlacesQuery;
use App\Query\GetHomepagePlacesResult;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAvailabilityChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Homepage places query — the bulk (6-query) replacement for the old
 * per-place/per-type/per-storage loops in HomeController. Every assertion
 * checks parity against the legacy per-storage path
 * ({@see StorageAvailabilityChecker::isAvailable()}), so the bulk rewrite can
 * never silently disagree with order-acceptance enforcement.
 */
final class GetHomepagePlacesQueryTest extends KernelTestCase
{
    private GetHomepagePlacesQuery $handler;
    private PlaceRepository $placeRepository;
    private StorageTypeRepository $storageTypeRepository;
    private StorageRepository $storageRepository;
    private StorageAvailabilityChecker $availabilityChecker;
    private \DateTimeImmutable $startDate;
    private \DateTimeImmutable $endDate;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->handler = $container->get(GetHomepagePlacesQuery::class);
        $this->placeRepository = $container->get(PlaceRepository::class);
        $this->storageTypeRepository = $container->get(StorageTypeRepository::class);
        $this->storageRepository = $container->get(StorageRepository::class);
        $this->availabilityChecker = $container->get(StorageAvailabilityChecker::class);

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->startDate = $clock->now()->modify('tomorrow');
        $this->endDate = $this->startDate->modify('+30 days');
    }

    public function testIncludesEveryActivePlaceExactlyOnce(): void
    {
        $result = ($this->handler)(new GetHomepagePlaces());

        $expectedIds = array_map(
            static fn (Place $place): string => $place->id->toRfc4122(),
            $this->placeRepository->findAllActive(),
        );
        $actualIds = array_map(
            static fn (GetHomepagePlaceRow $row): string => $row->place->id->toRfc4122(),
            $result->places,
        );

        sort($expectedIds);
        sort($actualIds);

        self::assertSame($expectedIds, $actualIds);
    }

    public function testTypesMatchPubliclyOrderableTypesPerPlaceInOrder(): void
    {
        $result = ($this->handler)(new GetHomepagePlaces());
        self::assertNotEmpty($result->places);

        foreach ($result->places as $row) {
            $expected = array_map(
                static fn (StorageType $type): string => $type->id->toRfc4122(),
                $this->storageTypeRepository->findPubliclyOrderableByPlace($row->place),
            );
            $actual = array_map(
                static fn ($typeRow): string => $typeRow->storageType->id->toRfc4122(),
                $row->storageTypes,
            );

            self::assertSame(
                $expected,
                $actual,
                sprintf('Typy pobočky "%s" musí odpovídat findPubliclyOrderableByPlace() včetně pořadí (admin-only a neaktivní typy vyloučeny).', $row->place->name),
            );
        }
    }

    public function testAvailabilityMatchesPerStorageChecker(): void
    {
        $result = ($this->handler)(new GetHomepagePlaces());
        self::assertNotEmpty($result->places);

        $sawAvailableType = false;
        $sawUnavailableType = false;

        foreach ($result->places as $row) {
            $placeAvailable = 0;

            foreach ($row->storageTypes as $typeRow) {
                $expectedAvailable = 0;
                foreach ($this->storageRepository->findByStorageTypeAndPlace($typeRow->storageType, $row->place) as $storage) {
                    if ($this->availabilityChecker->isAvailable($storage, $this->startDate, $this->endDate)) {
                        ++$expectedAvailable;
                    }
                }

                self::assertSame(
                    $expectedAvailable > 0,
                    $typeRow->isAvailable,
                    sprintf('Dostupnost typu "%s" na pobočce "%s" nesouhlasí s per-storage StorageAvailabilityChecker::isAvailable().', $typeRow->storageType->name, $row->place->name),
                );

                $placeAvailable += $expectedAvailable;
                $sawAvailableType = $sawAvailableType || $typeRow->isAvailable;
                $sawUnavailableType = $sawUnavailableType || !$typeRow->isAvailable;
            }

            self::assertSame(
                $placeAvailable > 0,
                $row->isAvailable,
                sprintf('Dostupnost pobočky "%s" nesouhlasí se součtem per-storage kontrol.', $row->place->name),
            );
        }

        self::assertTrue($sawAvailableType, 'Fixtures musí obsahovat aspoň jeden dostupný typ, jinak test nic neověřuje.');
        self::assertTrue($sawUnavailableType, 'Fixtures musí obsahovat aspoň jeden nedostupný typ, jinak test nic neověřuje.');
    }

    public function testLowestPriceAndAreaAreMinimaAcrossTypes(): void
    {
        $result = ($this->handler)(new GetHomepagePlaces());

        foreach ($result->places as $row) {
            if ([] === $row->storageTypes) {
                continue;
            }

            $expectedPrice = min(array_map(
                static fn ($typeRow): float => $typeRow->storageType->getDefaultPricePerMonthLongTermInCzk(),
                $row->storageTypes,
            ));
            $expectedArea = round(min(array_map(
                static fn ($typeRow): float => $typeRow->storageType->getFloorAreaInSquareMeters(),
                $row->storageTypes,
            )), 1);

            self::assertSame($expectedPrice, $row->lowestPrice, sprintf('lowestPrice pobočky "%s"', $row->place->name));
            self::assertSame($expectedArea, $row->lowestAreaM2, sprintf('lowestAreaM2 pobočky "%s"', $row->place->name));
        }
    }

    public function testPlaceWithoutOrderableTypesHasNoPriceAndIsUnavailable(): void
    {
        $result = ($this->handler)(new GetHomepagePlaces());

        $plzen = $this->findRowByName($result, 'Sklad Plzen');

        self::assertSame([], $plzen->storageTypes, 'Plzeň je map-only pobočka bez typů.');
        self::assertNull($plzen->lowestPrice);
        self::assertNull($plzen->lowestAreaM2);
        self::assertFalse($plzen->isAvailable);
    }

    public function testRowsSortedByAvailabilityRatioThenTotalThenName(): void
    {
        $result = ($this->handler)(new GetHomepagePlaces());
        self::assertGreaterThan(1, \count($result->places), 'Fixtures musí obsahovat aspoň dvě aktivní pobočky.');

        $sortKeys = [];
        foreach ($result->places as $row) {
            $total = 0;
            $available = 0;
            foreach ($row->storageTypes as $typeRow) {
                foreach ($this->storageRepository->findByStorageTypeAndPlace($typeRow->storageType, $row->place) as $storage) {
                    ++$total;
                    if ($this->availabilityChecker->isAvailable($storage, $this->startDate, $this->endDate)) {
                        ++$available;
                    }
                }
            }
            $sortKeys[] = [$total > 0 ? $available / $total : 0.0, $total, $row->place->name];
        }

        $previous = null;
        foreach ($sortKeys as $key) {
            if (null !== $previous) {
                // ratio DESC, total DESC, name ASC ⇔ [prevRatio, prevTotal, name] >= [ratio, total, prevName]
                self::assertGreaterThanOrEqual(
                    0,
                    [$previous[0], $previous[1], $key[2]] <=> [$key[0], $key[1], $previous[2]],
                    sprintf('Pobočka "%s" je zařazena před "%s" v rozporu s řazením ratio DESC, total DESC, name ASC.', $previous[2], $key[2]),
                );
            }
            $previous = $key;
        }
    }

    private function findRowByName(GetHomepagePlacesResult $result, string $name): GetHomepagePlaceRow
    {
        foreach ($result->places as $row) {
            if ($row->place->name === $name) {
                return $row;
            }
        }

        self::fail(sprintf('Pobočka "%s" nebyla ve výsledku nalezena.', $name));
    }
}
