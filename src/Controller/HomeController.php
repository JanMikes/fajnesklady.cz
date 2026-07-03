<?php

declare(strict_types=1);

namespace App\Controller;

use App\Query\GetHomepagePlaceRow;
use App\Query\GetHomepagePlaces;
use App\Query\GetHomepagePlaceTypeRow;
use App\Query\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/', name: 'app_home')]
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): Response
    {
        $result = $this->queryBus->handle(new GetHomepagePlaces());

        $placesData = array_map(
            fn (GetHomepagePlaceRow $row): array => [
                'id' => $row->place->id->toRfc4122(),
                'name' => $row->place->name,
                'address' => $row->place->address,
                'city' => $row->place->city,
                'latitude' => $row->place->latitude,
                'longitude' => $row->place->longitude,
                'type' => $row->place->type->value,
                'typeColor' => $row->place->type->color(),
                'url' => $this->urlGenerator->generate('public_place_detail', ['id' => $row->place->id]),
                'isAvailable' => $row->isAvailable,
                'lowestPrice' => $row->lowestPrice,
                'lowestAreaM2' => $row->lowestAreaM2,
                'storageTypes' => array_map(
                    fn (GetHomepagePlaceTypeRow $typeRow): array => [
                        'id' => $typeRow->storageType->id->toRfc4122(),
                        'name' => $typeRow->storageType->name,
                        'dimensions' => $typeRow->storageType->getInnerDimensionsInMeters(),
                        'floorAreaM2' => round($typeRow->storageType->getFloorAreaInSquareMeters(), 1),
                        'pricePerMonth' => $typeRow->storageType->getDefaultPricePerMonthLongTermInCzk(),
                        'isAvailable' => $typeRow->isAvailable,
                        'orderUrl' => $this->urlGenerator->generate('public_order_create', [
                            'placeId' => $row->place->id,
                            'storageTypeId' => $typeRow->storageType->id,
                        ]),
                    ],
                    $row->storageTypes,
                ),
            ],
            $result->places,
        );

        return $this->render('user/home.html.twig', [
            'placesWithStorageTypes' => $result->places,
            'placesData' => $placesData,
        ]);
    }
}
