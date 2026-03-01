<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Repository\PlaceRepository;
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

        return $this->render('portal/user/browse/place_detail.html.twig', [
            'place' => $place,
            'storageTypes' => $storageTypes,
            'availability' => $availability,
            'placeId' => $place->id->toRfc4122(),
        ]);
    }
}
