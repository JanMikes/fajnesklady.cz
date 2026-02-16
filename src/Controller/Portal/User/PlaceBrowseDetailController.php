<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAssignment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/pobocka/{id}', name: 'portal_browse_place_detail')]
#[IsGranted('ROLE_USER')]
final class PlaceBrowseDetailController extends AbstractController
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

        $storageTypes = $this->storageTypeRepository->findActiveByPlace($place);
        $storages = $this->storageRepository->findByPlace($place);

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

        return $this->render('portal/user/browse/place_detail.html.twig', [
            'place' => $place,
            'storageTypes' => $storageTypes,
            'availability' => $availability,
            'storagesJson' => json_encode($storagesData),
            'storageTypesJson' => json_encode($storageTypesData),
            'placeId' => $place->id->toRfc4122(),
        ]);
    }
}
