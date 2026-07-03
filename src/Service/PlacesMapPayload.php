<?php

declare(strict_types=1);

namespace App\Service;

use App\Query\GetPlacesOverviewRow;
use App\Query\GetPlacesOverviewTypeRow;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * JSON payload for the `map` Stimulus controller (and the homepage place
 * wizard), built from {@see \App\Query\GetPlacesOverview} rows. Every surface
 * embedding the map MUST emit this exact shape: the JS reads `isAvailable`
 * flags on places and types, so a payload without them renders every place as
 * "Obsazeno" (that was the /portal/pobocky map bug — it still sent the
 * pre-spec-048 `availableCount` field).
 */
final readonly class PlacesMapPayload
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<GetPlacesOverviewRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function build(array $rows, string $placeDetailRouteName): array
    {
        return array_map(
            fn (GetPlacesOverviewRow $row): array => [
                'id' => $row->place->id->toRfc4122(),
                'name' => $row->place->name,
                'address' => $row->place->address,
                'city' => $row->place->city,
                'latitude' => $row->place->latitude,
                'longitude' => $row->place->longitude,
                'type' => $row->place->type->value,
                'typeColor' => $row->place->type->color(),
                'url' => $this->urlGenerator->generate($placeDetailRouteName, ['id' => $row->place->id]),
                'isAvailable' => $row->isAvailable,
                'lowestPrice' => $row->lowestPrice,
                'lowestAreaM2' => $row->lowestAreaM2,
                'storageTypes' => array_map(
                    fn (GetPlacesOverviewTypeRow $typeRow): array => [
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
            $rows,
        );
    }
}
