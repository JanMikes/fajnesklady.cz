<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StorageType;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAssignment;
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
        private readonly StorageAssignment $storageAssignment,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): Response
    {
        $places = $this->placeRepository->findAllActive();

        $placesWithStorageTypes = [];
        $placesData = [];
        $startDate = new \DateTimeImmutable('tomorrow');
        $endDate = $startDate->modify('+30 days');

        foreach ($places as $place) {
            $storageTypes = $this->storageTypeRepository->findActiveByPlace($place);
            $lowestPrice = $this->getLowestPrice($storageTypes);

            $placesWithStorageTypes[] = [
                'place' => $place,
                'storageTypes' => $storageTypes,
                'lowestPrice' => $lowestPrice,
            ];

            // Prepare data for the map (JSON)
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
                'storageTypes' => array_map(
                    fn (StorageType $type) => [
                        'id' => $type->id->toRfc4122(),
                        'name' => $type->name,
                        'dimensions' => $type->getDimensions(),
                        'pricePerMonth' => $type->getDefaultPricePerMonthInCzk(),
                        'availableCount' => $this->storageAssignment->countAvailableStorages($type, $place, $startDate, $endDate),
                        'orderUrl' => $this->urlGenerator->generate('public_order_create', [
                            'placeId' => $place->id,
                            'storageTypeId' => $type->id,
                        ]),
                    ],
                    $storageTypes
                ),
            ];
        }

        return $this->render('user/home.html.twig', [
            'places' => $places,
            'placesWithStorageTypes' => $placesWithStorageTypes,
            'placesData' => $placesData,
        ]);
    }

    /**
     * @param StorageType[] $storageTypes
     */
    private function getLowestPrice(array $storageTypes): ?float
    {
        if (empty($storageTypes)) {
            return null;
        }

        $prices = array_map(
            fn (StorageType $type) => $type->getDefaultPricePerMonthInCzk(),
            $storageTypes
        );

        return min($prices);
    }
}
