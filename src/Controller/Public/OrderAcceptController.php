<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\AcceptOrderTermsCommand;
use App\Command\CreateOrderCommand;
use App\Command\GetOrCreateUserByEmailCommand;
use App\Command\SignOrderCommand;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\SigningMethod;
use App\Form\OrderFormData;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\PriceCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{placeId}/{storageTypeId}/{storageId}/prijmout', name: 'public_order_accept', requirements: ['placeId' => '[0-9a-f-]{36}', 'storageTypeId' => '[0-9a-f-]{36}', 'storageId' => '[0-9a-f-]{36}'])]
final class OrderAcceptController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PriceCalculator $priceCalculator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $placeId, string $storageTypeId, string $storageId, Request $request): Response
    {
        $place = $this->placeRepository->find(Uuid::fromString($placeId));
        if (null === $place || !$place->isActive) {
            throw new NotFoundHttpException('Pobočka nenalezena.');
        }

        $storageType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));
        if (null === $storageType || !$storageType->isActive) {
            throw new NotFoundHttpException('Typ skladové jednotky nenalezen.');
        }

        $storage = $this->storageRepository->find(Uuid::fromString($storageId));
        if (null === $storage) {
            throw new NotFoundHttpException('Skladová jednotka nenalezena.');
        }

        // Read form data from session
        $sessionData = $request->getSession()->get('order_form_data');
        if (!is_array($sessionData)) {
            $this->addFlash('error', 'Nejprve prosím vyplňte objednávkový formulář.');

            return $this->redirectToRoute('public_order_create', [
                'placeId' => $placeId,
                'storageTypeId' => $storageTypeId,
                'storageId' => $storageId,
            ]);
        }

        $formData = OrderFormData::fromSessionArray($sessionData);

        if (null === $formData->startDate) {
            $this->addFlash('error', 'Neplatná data objednávky. Vyplňte prosím formulář znovu.');

            return $this->redirectToRoute('public_order_create', [
                'placeId' => $placeId,
                'storageTypeId' => $storageTypeId,
                'storageId' => $storageId,
            ]);
        }

        $totalPrice = $this->priceCalculator->calculateFirstPaymentPrice($storage, $formData->startDate, $formData->endDate);

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $formData, $formData->startDate, $place, $storageType, $storage);
        }

        return $this->render('public/order_accept.html.twig', [
            'formData' => $formData,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'totalPrice' => $totalPrice,
        ]);
    }

    private function handlePost(
        Request $request,
        OrderFormData $formData,
        \DateTimeImmutable $startDate,
        \App\Entity\Place $place,
        \App\Entity\StorageType $storageType,
        \App\Entity\Storage $storage,
    ): Response {
        $accepted = $request->request->getBoolean('accept_contract');
        $signatureData = $request->request->getString('signature_data');
        $signingMethodValue = $request->request->getString('signing_method');
        $signatureConsent = $request->request->getBoolean('signature_consent');

        $errors = [];
        if (!$accepted) {
            $errors[] = 'Pro pokračování k platbě je nutné souhlasit se smluvními podmínkami.';
        }
        if ('' === $signatureData) {
            $errors[] = 'Pro pokračování je nutné přidat podpis.';
        }
        if (!$signatureConsent) {
            $errors[] = 'Pro pokračování je nutné potvrdit souhlas s elektronickým podpisem.';
        }

        $signingMethod = SigningMethod::tryFrom($signingMethodValue);
        if ('' !== $signingMethodValue && null === $signingMethod) {
            $errors[] = 'Neplatná metoda podpisu.';
        }

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->render('public/order_accept.html.twig', [
                'formData' => $formData,
                'storage' => $storage,
                'storageType' => $storageType,
                'place' => $place,
                'totalPrice' => $this->priceCalculator->calculateFirstPaymentPrice($storage, $startDate, $formData->endDate),
            ]);
        }

        try {
            // 1. Get or create user
            $envelope = $this->commandBus->dispatch(new GetOrCreateUserByEmailCommand(
                email: $formData->email,
                firstName: $formData->firstName,
                lastName: $formData->lastName,
                phone: $formData->phone,
                plainPassword: $formData->plainPassword,
            ));

            $handledStamp = $envelope->last(HandledStamp::class);
            $user = $handledStamp?->getResult();

            if (!$user instanceof User) {
                throw new \RuntimeException('Failed to create user.');
            }

            if (null === $formData->rentalType) {
                throw new \RuntimeException('Invalid form data.');
            }

            // 2. Create order (CREATED status, no reservation yet)
            $orderEnvelope = $this->commandBus->dispatch(new CreateOrderCommand(
                user: $user,
                storageType: $storageType,
                place: $place,
                rentalType: $formData->rentalType,
                startDate: $startDate,
                endDate: $formData->endDate,
                paymentFrequency: PaymentFrequency::MONTHLY,
                preSelectedStorage: $storage,
            ));

            $orderHandledStamp = $orderEnvelope->last(HandledStamp::class);
            $order = $orderHandledStamp?->getResult();

            if (!$order instanceof Order) {
                throw new \RuntimeException('Failed to create order.');
            }

            // 3. Sign order
            /** @var SigningMethod $signingMethod */
            $typedName = $request->request->getString('typed_name') ?: null;
            $styleId = $request->request->getString('style_id') ?: null;

            $this->commandBus->dispatch(new SignOrderCommand(
                order: $order,
                signatureDataUrl: $signatureData,
                signingMethod: $signingMethod,
                typedName: $typedName,
                styleId: $styleId,
            ));

            // 4. Accept terms + reserve storage
            $this->commandBus->dispatch(new AcceptOrderTermsCommand($order));

            // Clear session data
            $request->getSession()->remove('order_form_data');

            $this->addFlash('success', 'Smlouva byla podepsána a skladová jednotka zarezervována. Pokračujte k platbě.');

            return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
        } catch (\App\Exception\NoStorageAvailable $e) {
            $this->addFlash('error', 'Omlouváme se, ale vybraná skladová jednotka již není dostupná.');

            return $this->redirectToRoute('public_order_create', [
                'placeId' => $place->id,
                'storageTypeId' => $storageType->id,
                'storageId' => $storage->id,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Order creation failed during acceptance', [
                'place_id' => $place->id->toRfc4122(),
                'storage_id' => $storage->id->toRfc4122(),
                'exception' => $e,
            ]);
            $this->addFlash('error', 'Při vytváření objednávky došlo k chybě. Zkuste to prosím znovu.');

            return $this->render('public/order_accept.html.twig', [
                'formData' => $formData,
                'storage' => $storage,
                'storageType' => $storageType,
                'place' => $place,
                'totalPrice' => $this->priceCalculator->calculateFirstPaymentPrice($storage, $startDate, $formData->endDate),
            ]);
        }
    }
}
