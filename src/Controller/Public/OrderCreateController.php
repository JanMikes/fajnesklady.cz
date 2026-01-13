<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CreateOrderCommand;
use App\Command\GetOrCreateUserByEmailCommand;
use App\Entity\Order;
use App\Entity\User;
use App\Form\OrderFormData;
use App\Form\OrderFormType;
use App\Repository\StorageTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{storageTypeId}', name: 'public_order_create')]
final class OrderCreateController extends AbstractController
{
    public function __construct(
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $storageTypeId, Request $request): Response
    {
        if (!Uuid::isValid($storageTypeId)) {
            throw new NotFoundHttpException('Typ skladové jednotky nenalezen.');
        }

        $storageType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));

        if (null === $storageType || !$storageType->isActive) {
            throw new NotFoundHttpException('Typ skladové jednotky nenalezen.');
        }

        $place = $storageType->place;
        if (!$place->isActive) {
            throw new NotFoundHttpException('Pobočka není aktivní.');
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
                    rentalType: $formData->rentalType,
                    startDate: $formData->startDate,
                    endDate: $formData->endDate,
                    paymentFrequency: $formData->paymentFrequency,
                ));

                $orderHandledStamp = $orderEnvelope->last(HandledStamp::class);
                $order = $orderHandledStamp?->getResult();

                if (!$order instanceof Order) {
                    throw new \RuntimeException('Failed to create order.');
                }

                $this->addFlash('success', 'Objednávka byla vytvořena. Pokračujte k platbě.');

                return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
            } catch (\App\Exception\NoStorageAvailable $e) {
                $this->addFlash('error', 'Omlouváme se, ale vybraný typ skladové jednotky již není pro zvolené období dostupný.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Při vytváření objednávky došlo k chybě. Zkuste to prosím znovu.');
            }
        }

        // Calculate example prices for display
        $weeklyPrice = $storageType->getPricePerWeekInCzk();
        $monthlyPrice = $storageType->getPricePerMonthInCzk();

        return $this->render('public/order_create.html.twig', [
            'storageType' => $storageType,
            'place' => $place,
            'form' => $form,
            'weeklyPrice' => $weeklyPrice,
            'monthlyPrice' => $monthlyPrice,
            'minStartDate' => $this->calculateMinStartDate($place->daysInAdvance),
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
