<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\PlaceRepository;
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

        return $this->render('public/place_detail.html.twig', [
            'place' => $place,
            'storageTypes' => $storageTypes,
            'availability' => $availability,
            'placeId' => $place->id->toRfc4122(),
        ]);
    }
}
