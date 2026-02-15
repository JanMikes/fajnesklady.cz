<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CreateOrderCommand;
use App\Command\GetOrCreateUserByEmailCommand;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Form\OrderFormData;
use App\Form\OrderFormType;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{placeId}/{storageTypeId}/{storageId?}', name: 'public_order_create', requirements: ['placeId' => '[0-9a-f-]{36}', 'storageTypeId' => '[0-9a-f-]{36}', 'storageId' => '[0-9a-f-]{36}'])]
final class OrderCreateController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly MessageBusInterface $commandBus,
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

        // Handle storage selection for non-uniform storage types
        $preSelectedStorage = null;
        if (!$storageType->uniformStorages) {
            // Non-uniform storage type requires a specific storage to be selected
            if (null === $storageId || !Uuid::isValid($storageId)) {
                throw new BadRequestHttpException('Pro tento typ skladu je nutné vybrat konkrétní úložiště z mapy.');
            }
            $preSelectedStorage = $this->storageRepository->find(Uuid::fromString($storageId));
            if (null === $preSelectedStorage) {
                throw new NotFoundHttpException('Skladová jednotka nenalezena.');
            }
            // Validate that storage belongs to the correct type and place
            if (!$preSelectedStorage->storageType->id->equals($storageType->id)) {
                throw new BadRequestHttpException('Vybraná skladová jednotka nepatří k vybranému typu.');
            }
            if (!$preSelectedStorage->place->id->equals($place->id)) {
                throw new BadRequestHttpException('Vybraná skladová jednotka nepatří k vybrané pobočce.');
            }
            if (!$preSelectedStorage->isAvailable()) {
                throw new BadRequestHttpException('Vybraná skladová jednotka již není dostupná.');
            }
        } elseif (null !== $storageId) {
            // Uniform storage type should not have a storage pre-selected
            throw new BadRequestHttpException('Pro tento typ skladu nelze vybrat konkrétní úložiště.');
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $formData = OrderFormData::fromUser($user);
        } else {
            $formData = new OrderFormData();
        }
        $formData->startDate = $this->calculateMinStartDate($place->daysInAdvance);

        $form = $this->createForm(OrderFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Get or create user
                $envelope = $this->commandBus->dispatch(new GetOrCreateUserByEmailCommand(
                    email: $formData->email,
                    firstName: $formData->firstName,
                    lastName: $formData->lastName,
                    phone: $formData->phone,
                    plainPassword: $formData->plainPassword,
                ));

                $handledStamp = $envelope->last(HandledStamp::class);
                $user = $handledStamp?->getResult();

                if (null === $user) {
                    throw new \RuntimeException('Failed to create user.');
                }

                // Validated by the form, but PHPStan needs explicit check
                if (null === $formData->rentalType) {
                    throw new \RuntimeException('Invalid form data.');
                }

                // Create order
                $orderEnvelope = $this->commandBus->dispatch(new CreateOrderCommand(
                    user: $user,
                    storageType: $storageType,
                    place: $place,
                    rentalType: $formData->rentalType,
                    startDate: $formData->startDate,
                    endDate: $formData->endDate,
                    paymentFrequency: $formData->paymentFrequency,
                    preSelectedStorage: $preSelectedStorage,
                ));

                $orderHandledStamp = $orderEnvelope->last(HandledStamp::class);
                $order = $orderHandledStamp?->getResult();

                if (!$order instanceof Order) {
                    throw new \RuntimeException('Failed to create order.');
                }

                $this->addFlash('success', 'Objednávka byla vytvořena. Přijměte prosím smluvní podmínky.');

                return $this->redirectToRoute('public_order_accept', ['id' => $order->id]);
            } catch (\App\Exception\NoStorageAvailable $e) {
                $this->addFlash('error', 'Omlouváme se, ale vybraný typ skladové jednotky již není pro zvolené období dostupný.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Při vytváření objednávky došlo k chybě. Zkuste to prosím znovu.');
            }
        }

        // Calculate example prices for display (use storage's effective prices if pre-selected)
        if (null !== $preSelectedStorage) {
            $weeklyPrice = $preSelectedStorage->getEffectivePricePerWeekInCzk();
            $monthlyPrice = $preSelectedStorage->getEffectivePricePerMonthInCzk();
        } else {
            $weeklyPrice = $storageType->getDefaultPricePerWeekInCzk();
            $monthlyPrice = $storageType->getDefaultPricePerMonthInCzk();
        }

        return $this->render('public/order_create.html.twig', [
            'storageType' => $storageType,
            'place' => $place,
            'form' => $form,
            'weeklyPrice' => $weeklyPrice,
            'monthlyPrice' => $monthlyPrice,
            'minStartDate' => $this->calculateMinStartDate($place->daysInAdvance),
            'preSelectedStorage' => $preSelectedStorage,
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
