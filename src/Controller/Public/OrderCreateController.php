<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Form\OrderFormType;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\StorageAssignment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    ) {
    }

    public function __invoke(string $placeId, string $storageTypeId, Request $request, ?string $storageId = null): Response
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

        if (null === $storageType || !$storageType->isActive) {
            throw new NotFoundHttpException('Typ skladové jednotky nenalezen.');
        }

        // Auto-select storage if not provided
        if (null === $storageId) {
            $startDate = new \DateTimeImmutable('tomorrow');
            $endDate = $startDate->modify('+30 days');
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
        if (!$preSelectedStorage->isAvailable()) {
            // Try to find another available storage of the same type
            $startDate = new \DateTimeImmutable('tomorrow');
            $endDate = $startDate->modify('+30 days');
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

        $user = $this->getUser();
        $sessionData = $request->getSession()->get('order_form_data');
        if (is_array($sessionData)) {
            $formData = OrderFormData::fromSessionArray($sessionData);
        } elseif ($user instanceof User) {
            $formData = OrderFormData::fromUser($user);
        } else {
            $formData = new OrderFormData();
        }
        $formData->startDate ??= $this->calculateMinStartDate($place->daysInAdvance);

        $form = $this->createForm(OrderFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Clear endDate for unlimited rentals - the form field is only hidden, not removed
            if (RentalType::UNLIMITED === $formData->rentalType) {
                $formData->endDate = null;
            }

            $request->getSession()->set('order_form_data', $formData->toSessionArray());

            return $this->redirectToRoute('public_order_accept', [
                'placeId' => $placeId,
                'storageTypeId' => $storageTypeId,
                'storageId' => $storageId,
            ]);
        }

        // Calculate example prices for display (use storage's effective prices)
        $weeklyPrice = $preSelectedStorage->getEffectivePricePerWeekInCzk();
        $monthlyPrice = $preSelectedStorage->getEffectivePricePerMonthInCzk();

        // Prepare storage data for the map
        $storages = $this->storageRepository->findByPlace($place);
        $storagesData = array_map(function ($s) {
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
            ];
        }, $storages);

        return $this->render('public/order_create.html.twig', [
            'storageType' => $storageType,
            'place' => $place,
            'form' => $form,
            'weeklyPrice' => $weeklyPrice,
            'monthlyPrice' => $monthlyPrice,
            'minStartDate' => $this->calculateMinStartDate($place->daysInAdvance),
            'preSelectedStorage' => $preSelectedStorage,
            'storagesJson' => json_encode($storagesData),
            'highlightStorageId' => $preSelectedStorage->id->toRfc4122(),
        ]);
    }

    private function calculateMinStartDate(int $daysInAdvance): \DateTimeImmutable
    {
        $minDate = new \DateTimeImmutable('tomorrow');

        if ($daysInAdvance > 0) {
            $minDate = $minDate->modify("+{$daysInAdvance} days");
        }

        return $minDate;
    }
}
