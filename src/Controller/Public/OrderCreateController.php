<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAssignment;
use App\Service\StorageAvailabilityChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{placeId}/{storageTypeId}/{storageId?}', name: 'public_order_create', requirements: ['placeId' => '[0-9a-f-]{36}', 'storageTypeId' => '[0-9a-f-]{36}', 'storageId' => '[0-9a-f-]{36}'])]
final class OrderCreateController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageAssignment $storageAssignment,
        private readonly StorageAvailabilityChecker $availabilityChecker,
    ) {
    }

    public function __invoke(string $placeId, string $storageTypeId, ?string $storageId = null): Response
    {
        if (!Uuid::isValid($placeId)) {
            throw new NotFoundHttpException('Pobočka nenalezena.');
        }

        if (!Uuid::isValid($storageTypeId)) {
            throw new NotFoundHttpException('Typ skladové jednotky nenalezen.');
        }

        $place = $this->placeRepository->find(Uuid::fromString($placeId));

        if (null === $place || !$place->isActive) {
            throw new NotFoundHttpException('Pobočka nenalezena.');
        }

        $storageType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));

        // adminOnly types are reserved for admin onboarding and must never be
        // publicly orderable — reject a direct/guessed URL the same as a missing type.
        if (null === $storageType || !$storageType->isActive || $storageType->adminOnly) {
            throw new NotFoundHttpException('Typ skladové jednotky nenalezen.');
        }

        $startDate = new \DateTimeImmutable('tomorrow');
        $endDate = $startDate->modify('+30 days');

        // Auto-select storage if not provided
        if (null === $storageId) {
            $firstAvailable = $this->storageAssignment->findFirstAvailableStorage($storageType, $place, $startDate, $endDate);

            if (null === $firstAvailable) {
                $this->addFlash('error', 'Omlouváme se, ale tento typ skladové jednotky není momentálně dostupný.');

                return $this->redirectToRoute(
                    $this->getUser() ? 'portal_browse_place_detail' : 'public_place_detail',
                    ['id' => $placeId],
                );
            }

            return $this->redirectToRoute('public_order_create', [
                'placeId' => $placeId,
                'storageTypeId' => $storageTypeId,
                'storageId' => $firstAvailable->id->toRfc4122(),
            ]);
        }

        // Validate provided storageId
        if (!Uuid::isValid($storageId)) {
            throw new NotFoundHttpException('Skladová jednotka nenalezena.');
        }
        $preSelectedStorage = $this->storageRepository->find(Uuid::fromString($storageId));
        if (null === $preSelectedStorage) {
            throw new NotFoundHttpException('Skladová jednotka nenalezena.');
        }
        if (!$preSelectedStorage->storageType->id->equals($storageType->id)) {
            throw new BadRequestHttpException('Vybraná skladová jednotka nepatří k vybranému typu.');
        }
        if (!$preSelectedStorage->place->id->equals($place->id)) {
            throw new BadRequestHttpException('Vybraná skladová jednotka nepatří k vybrané pobočce.');
        }
        // Use the same checker as findFirstAvailableStorage so we never redirect to a storage
        // the checker would hand back to us — which would otherwise cause a redirect loop
        // when the entity status (e.g. "occupied") disagrees with the date-window check.
        if (!$this->availabilityChecker->isAvailable($preSelectedStorage, $startDate, $endDate)) {
            $alternative = $this->storageAssignment->findFirstAvailableStorage($storageType, $place, $startDate, $endDate);

            if (null !== $alternative) {
                return $this->redirectToRoute('public_order_create', [
                    'placeId' => $placeId,
                    'storageTypeId' => $storageTypeId,
                    'storageId' => $alternative->id->toRfc4122(),
                ]);
            }

            $this->addFlash('error', 'Omlouváme se, ale tento typ skladové jednotky není momentálně dostupný.');

            return $this->redirectToRoute(
                $this->getUser() ? 'portal_browse_place_detail' : 'public_place_detail',
                ['id' => $placeId],
            );
        }

        // The OrderForm Live Component owns the map payload now (it derives
        // per-storage availability for the customer's chosen window), so the
        // controller only needs to hand it the pre-selected unit + context.
        return $this->render('public/order_create.html.twig', [
            'storageType' => $storageType,
            'place' => $place,
            'preSelectedStorage' => $preSelectedStorage,
        ]);
    }
}
