<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAvailabilityChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/', name: 'app_home')]
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageAvailabilityChecker $availabilityChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): Response
    {
        $places = $this->placeRepository->findAllActive();

        $startDate = new \DateTimeImmutable('tomorrow');
        $endDate = $startDate->modify('+30 days');

        $placesWithStorageTypes = [];
        $placesData = [];
        /** @var array<string, array{0: float, 1: int}> $sortKeyById */
        $sortKeyById = [];

        foreach ($places as $place) {
            $storageTypes = $this->storageTypeRepository->findActiveByPlace($place);

            $placeTotal = 0;
            $placeAvailable = 0;
            $lowestPrice = null;
            $lowestAreaM2 = null;
            $typesPayload = [];

            foreach ($storageTypes as $type) {
                [$totalOfType, $availableOfType] = $this->countTypeAtPlace($type, $place, $startDate, $endDate);

                $placeTotal += $totalOfType;
                $placeAvailable += $availableOfType;

                $priceCzk = $type->getDefaultPricePerMonthLongTermInCzk();
                $areaM2 = $type->getFloorAreaInSquareMeters();
                $lowestPrice = null === $lowestPrice ? $priceCzk : min($lowestPrice, $priceCzk);
                $lowestAreaM2 = null === $lowestAreaM2 ? $areaM2 : min($lowestAreaM2, $areaM2);

                $typesPayload[] = [
                    'id' => $type->id->toRfc4122(),
                    'name' => $type->name,
                    'dimensions' => $type->getInnerDimensionsInMeters(),
                    'floorAreaM2' => round($areaM2, 1),
                    'pricePerMonth' => $priceCzk,
                    'isAvailable' => $availableOfType > 0,
                    'orderUrl' => $this->urlGenerator->generate('public_order_create', [
                        'placeId' => $place->id,
                        'storageTypeId' => $type->id,
                    ]),
                ];
            }

            $availabilityRatio = $placeTotal > 0 ? $placeAvailable / $placeTotal : 0.0;
            $sortKeyById[$place->id->toRfc4122()] = [$availabilityRatio, $placeTotal];

            $placesWithStorageTypes[] = [
                'place' => $place,
                'storageTypes' => $storageTypes,
                'lowestPrice' => $lowestPrice,
                'lowestAreaM2' => null !== $lowestAreaM2 ? round($lowestAreaM2, 1) : null,
                'isAvailable' => $placeAvailable > 0,
            ];

            $placesData[] = [
                'id' => $place->id->toRfc4122(),
                'name' => $place->name,
                'address' => $place->address,
                'city' => $place->city,
                'latitude' => $place->latitude,
                'longitude' => $place->longitude,
                'type' => $place->type->value,
                'typeColor' => $place->type->color(),
                'url' => $this->urlGenerator->generate('public_place_detail', ['id' => $place->id]),
                'isAvailable' => $placeAvailable > 0,
                'lowestPrice' => $lowestPrice,
                'lowestAreaM2' => null !== $lowestAreaM2 ? round($lowestAreaM2, 1) : null,
                'storageTypes' => $typesPayload,
            ];
        }

        usort(
            $placesWithStorageTypes,
            fn (array $a, array $b): int => $this->compareByAvailability(
                $sortKeyById[$a['place']->id->toRfc4122()] ?? [0.0, 0],
                $sortKeyById[$b['place']->id->toRfc4122()] ?? [0.0, 0],
                $a['place']->name,
                $b['place']->name,
            ),
        );

        usort(
            $placesData,
            fn (array $a, array $b): int => $this->compareByAvailability(
                $sortKeyById[$a['id']] ?? [0.0, 0],
                $sortKeyById[$b['id']] ?? [0.0, 0],
                $a['name'],
                $b['name'],
            ),
        );

        return $this->render('user/home.html.twig', [
            'places' => $places,
            'placesWithStorageTypes' => $placesWithStorageTypes,
            'placesData' => $placesData,
        ]);
    }

    /**
     * @return array{0: int, 1: int} [total, available]
     */
    private function countTypeAtPlace(
        StorageType $storageType,
        Place $place,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        $storages = $this->storageRepository->findByStorageTypeAndPlace($storageType, $place);
        $available = 0;
        foreach ($storages as $storage) {
            if ($this->availabilityChecker->isAvailable($storage, $startDate, $endDate)) {
                ++$available;
            }
        }

        return [\count($storages), $available];
    }

    /**
     * (ratio DESC, total DESC, name ASC).
     *
     * @param array{0: float, 1: int} $a
     * @param array{0: float, 1: int} $b
     */
    private function compareByAvailability(array $a, array $b, string $nameA, string $nameB): int
    {
        return [$b[0], $b[1], $nameA] <=> [$a[0], $a[1], $nameB];
    }
}
