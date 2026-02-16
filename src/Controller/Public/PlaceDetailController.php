<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAssignment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/pobocka/{id}', name: 'public_place_detail')]
final class PlaceDetailController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageAssignment $storageAssignment,
    ) {
    }

    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Pobočka nenalezena.');
        }

        $place = $this->placeRepository->find(Uuid::fromString($id));

        if (null === $place || !$place->isActive) {
            throw new NotFoundHttpException('Pobočka nenalezena.');
        }

        if ($this->getUser()) {
            return $this->redirectToRoute('portal_browse_place_detail', ['id' => $id]);
        }

        $storageTypes = $this->storageTypeRepository->findActiveByPlace($place);
        $storages = $this->storageRepository->findByPlace($place);

        // Calculate availability for next 30 days for each storage type
        $startDate = new \DateTimeImmutable('tomorrow');
        $endDate = $startDate->modify('+30 days');

        $availability = [];
        foreach ($storageTypes as $storageType) {
            $availability[$storageType->id->toRfc4122()] = $this->storageAssignment->countAvailableStorages(
                $storageType,
                $place,
                $startDate,
                $endDate
            );
        }

        // Prepare storage data for the map
        $storagesData = array_map(function ($s) {
            $photos = $s->getPhotos();
            $photoUrls = array_map(
                fn ($photo) => '/uploads/'.$photo->path,
                $photos->toArray(),
            );
            $photoUrl = !empty($photoUrls) ? $photoUrls[0] : null;

            return [
                'id' => $s->id->toRfc4122(),
                'number' => $s->number,
                'storageTypeId' => $s->storageType->id->toRfc4122(),
                'storageTypeName' => $s->storageType->name,
                'coordinates' => $s->coordinates,
                'status' => $s->status->value,
                'dimensions' => $s->storageType->getDimensionsInMeters(),
                'pricePerWeek' => $s->getEffectivePricePerWeekInCzk(),
                'pricePerMonth' => $s->getEffectivePricePerMonthInCzk(),
                'isUniform' => $s->storageType->uniformStorages,
                'photoUrl' => $photoUrl,
                'photoUrls' => array_values($photoUrls),
            ];
        }, $storages);

        $storageTypesData = array_map(fn ($t) => [
            'id' => $t->id->toRfc4122(),
            'name' => $t->name,
            'dimensions' => $t->getDimensionsInMeters(),
            'uniformStorages' => $t->uniformStorages,
        ], $storageTypes);

        // Check if any non-uniform storage type exists
        $hasNonUniformTypes = array_reduce(
            $storageTypes,
            fn ($carry, $t) => $carry || !$t->uniformStorages,
            false
        );

        return $this->render('public/place_detail.html.twig', [
            'place' => $place,
            'storageTypes' => $storageTypes,
            'availability' => $availability,
            'storagesJson' => json_encode($storagesData),
            'storageTypesJson' => json_encode($storageTypesData),
            'hasNonUniformTypes' => $hasNonUniformTypes,
            'placeId' => $place->id->toRfc4122(),
        ]);
    }
}
