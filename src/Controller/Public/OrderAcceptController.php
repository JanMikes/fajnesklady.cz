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
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\PriceCalculator;
use App\Service\StorageAssignment;
use App\Service\StorageAvailabilityChecker;
use Psr\Clock\ClockInterface;
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
        private readonly StorageAvailabilityChecker $availabilityChecker,
        private readonly StorageAssignment $storageAssignment,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
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

        // Re-validate availability against the actually-chosen dates (not the upstream 30-day window
        // the OrderCreateController used). When the unit conflicts now, branch on the user's intent:
        //   auto   → silently swap to another unit of the same type+place and redirect to its URL.
        //   manual → surface a clear "the unit you picked was just taken" message and bounce to the map.
        if (!$this->availabilityChecker->isAvailable($storage, $formData->startDate, $formData->endDate)) {
            return $this->handleStorageNoLongerAvailable($place, $storageType, $storage, $formData);
        }

        $now = $this->clock->now();
        $paymentSchedule = $this->priceCalculator->buildPaymentSchedule($storage, $formData->startDate, $formData->endDate);
        $requiresEarlyStartWaiver = $formData->startDate < $now->setTime(0, 0, 0)->modify('+14 days');

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $formData, $formData->startDate, $place, $storageType, $storage, $requiresEarlyStartWaiver, $now);
        }

        return $this->render('public/order_accept.html.twig', [
            'formData' => $formData,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'paymentSchedule' => $paymentSchedule,
            'isRecurring' => $paymentSchedule->isRecurring,
            'requiresEarlyStartWaiver' => $requiresEarlyStartWaiver,
            'recurringPaymentLegalMaxInCzk' => intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100),
            'submitted' => self::emptySubmittedValues(),
            'now' => $now,
        ]);
    }

    /**
     * @return array{signingPlace: string, acceptAll: bool, acceptRecurring: bool}
     */
    private static function emptySubmittedValues(): array
    {
        return ['signingPlace' => '', 'acceptAll' => false, 'acceptRecurring' => false];
    }

    /**
     * Pre-selected storage is no longer bookable for the user's chosen dates.
     * In 'auto' mode we silently route to any other free unit of the same type+place;
     * in 'manual' mode we surface the conflict so the user can pick a different unit themselves.
     */
    private function handleStorageNoLongerAvailable(
        \App\Entity\Place $place,
        \App\Entity\StorageType $storageType,
        \App\Entity\Storage $storage,
        OrderFormData $formData,
    ): Response {
        if ('manual' === $formData->selectionMode) {
            $this->addFlash('error', sprintf('Vámi zvolená skladová jednotka č. %s byla mezitím obsazena. Vyberte prosím jinou.', $storage->number));

            return $this->redirectToRoute('public_order_create', [
                'placeId' => $place->id->toRfc4122(),
                'storageTypeId' => $storageType->id->toRfc4122(),
            ]);
        }

        // Auto mode — try to swap to another free unit of the same type+place silently.
        $alternative = $this->storageAssignment->findFirstAvailableStorage(
            $storageType,
            $place,
            $formData->startDate ?? new \DateTimeImmutable('tomorrow'),
            $formData->endDate,
        );

        if (null !== $alternative) {
            return $this->redirectToRoute('public_order_accept', [
                'placeId' => $place->id->toRfc4122(),
                'storageTypeId' => $storageType->id->toRfc4122(),
                'storageId' => $alternative->id->toRfc4122(),
            ]);
        }

        $this->addFlash('error', 'Omlouváme se, ale tento typ skladové jednotky již není pro vámi zvolené období dostupný.');

        return $this->redirectToRoute('public_place_detail', ['id' => $place->id->toRfc4122()]);
    }

    private function handlePost(
        Request $request,
        OrderFormData $formData,
        \DateTimeImmutable $startDate,
        \App\Entity\Place $place,
        \App\Entity\StorageType $storageType,
        \App\Entity\Storage $storage,
        bool $requiresEarlyStartWaiver,
        \DateTimeImmutable $now,
    ): Response {
        $accepted = $request->request->getBoolean('accept_contract');
        $signatureData = $request->request->getString('signature_data');
        $signingMethodValue = $request->request->getString('signing_method');
        $signatureConsent = $request->request->getBoolean('signature_consent');
        $acceptOperatingRules = $request->request->getBoolean('accept_operating_rules');
        $acceptVop = $request->request->getBoolean('accept_vop');
        $acceptConsumerNotice = $request->request->getBoolean('accept_consumer_notice');
        $acceptGdpr = $request->request->getBoolean('accept_gdpr');
        $acceptRecurringPayments = $request->request->getBoolean('accept_recurring_payments');
        $acceptEarlyStartWaiver = $request->request->getBoolean('accept_early_start_waiver');
        $signingPlace = trim($request->request->getString('signing_place'));

        $isRecurring = $this->priceCalculator->needsRecurringBilling($startDate, $formData->endDate);

        $errors = [];
        if (!$accepted) {
            $errors[] = 'Pro pokračování k platbě je nutné souhlasit se smluvními podmínkami.';
        }
        if (!$acceptVop) {
            $errors[] = 'Pro pokračování je nutné souhlasit s všeobecnými obchodními podmínkami.';
        }
        if (null !== $place->operatingRulesPath && !$acceptOperatingRules) {
            $errors[] = 'Pro pokračování je nutné souhlasit s provozním řádem.';
        }
        if (!$acceptConsumerNotice) {
            $errors[] = 'Pro pokračování je nutné souhlasit s poučením o právech spotřebitele.';
        }
        if (!$acceptGdpr) {
            $errors[] = 'Pro pokračování je nutné souhlasit se zpracováním osobních údajů.';
        }
        // For MANUAL_RECURRING the customer is not consenting to a stored-card
        // recurring charge — they pay each cycle one-time via an e-mail link.
        // The dedicated "Souhlasím s opakovanou platbou" consent is hidden by
        // order_accept.html.twig in that case, so the form does not send it.
        if ($isRecurring && \App\Enum\BillingMode::AUTO_RECURRING === $formData->resolvedBillingMode() && !$acceptRecurringPayments) {
            $errors[] = 'Pro pokračování je nutné souhlasit s podmínkami opakovaných plateb.';
        }
        if ($requiresEarlyStartWaiver && !$acceptEarlyStartWaiver) {
            $errors[] = 'Pro pokračování je nutné souhlasit se vzdáním se práva na odstoupení od smlouvy ve 14denní lhůtě.';
        }
        if ('' === $signatureData) {
            $errors[] = 'Pro pokračování je nutné přidat podpis.';
        }
        if ('' === $signingPlace) {
            $errors[] = 'Pro pokračování je nutné vyplnit místo podpisu.';
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

            $paymentSchedule = $this->priceCalculator->buildPaymentSchedule($storage, $startDate, $formData->endDate);

            return $this->render('public/order_accept.html.twig', [
                'formData' => $formData,
                'storage' => $storage,
                'storageType' => $storageType,
                'place' => $place,
                'paymentSchedule' => $paymentSchedule,
                'isRecurring' => $paymentSchedule->isRecurring,
                'requiresEarlyStartWaiver' => $requiresEarlyStartWaiver,
                'recurringPaymentLegalMaxInCzk' => intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100),
                'submitted' => [
                    'signingPlace' => $signingPlace,
                    'acceptAll' => $accepted && $acceptVop && $acceptConsumerNotice && $acceptGdpr && $signatureConsent
                        && (null === $place->operatingRulesPath || $acceptOperatingRules)
                        && (!$requiresEarlyStartWaiver || $acceptEarlyStartWaiver),
                    'acceptRecurring' => $acceptRecurringPayments,
                ],
                'now' => $now,
            ]);
        }

        try {
            // 1. Get or create user
            // Company fields are only carried over when the user explicitly toggled
            // "fakturovat na společnost" — otherwise their saved profile is preserved
            // by the handler.
            $envelope = $this->commandBus->dispatch(new GetOrCreateUserByEmailCommand(
                email: $formData->email,
                firstName: $formData->firstName,
                lastName: $formData->lastName,
                phone: $formData->phone,
                birthDate: $formData->birthDate,
                plainPassword: $formData->plainPassword,
                companyName: $formData->invoiceToCompany ? $formData->companyName : null,
                companyId: $formData->invoiceToCompany ? $formData->companyId : null,
                companyVatId: $formData->invoiceToCompany ? $formData->companyVatId : null,
                billingStreet: $formData->billingStreet,
                billingCity: $formData->billingCity,
                billingPostalCode: $formData->billingPostalCode,
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

            // Lock the customer's billing-mode choice onto the order. AUTO is
            // the default; only MANUAL needs an explicit set. The form layer
            // already enforced eligibility (UNLIMITED forced AUTO, short
            // LIMITED forced ONE_TIME) via OrderFormData::validateBillingMode.
            $billingMode = $formData->resolvedBillingMode();
            if (\App\Enum\BillingMode::AUTO_RECURRING !== $billingMode) {
                $order->setBillingMode($billingMode);
            }

            // 3. Sign order
            /** @var SigningMethod $signingMethod */
            $typedName = $request->request->getString('typed_name') ?: null;
            $styleId = $request->request->getString('style_id') ?: null;

            $this->commandBus->dispatch(new SignOrderCommand(
                order: $order,
                signatureDataUrl: $signatureData,
                signingMethod: $signingMethod,
                signingPlace: $signingPlace,
                typedName: $typedName,
                styleId: $styleId,
                signerIpAddress: $request->getClientIp(),
                signerUserAgent: substr((string) $request->headers->get('User-Agent', ''), 0, 500) ?: null,
            ));

            // 4. Accept terms + reserve storage
            $this->commandBus->dispatch(new AcceptOrderTermsCommand(
                order: $order,
                earlyStartWaiverAccepted: $requiresEarlyStartWaiver && $acceptEarlyStartWaiver,
            ));

            // Clear session data
            $request->getSession()->remove('order_form_data');

            $this->addFlash('success', 'Smlouva byla podepsána a skladová jednotka zarezervována. Pokračujte k platbě.');

            return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
        } catch (\Throwable $rawException) {
            // Messenger wraps handler exceptions in HandlerFailedException, so typed catches
            // never match the original — unwrap before branching. See .claude/MESSENGER.md.
            $exception = HandlerFailureUnwrap::unwrap($rawException);

            if ($exception instanceof \App\Exception\NoStorageAvailable) {
                // Race: the unit became unavailable between the top-of-controller re-check and now.
                // Route the user the same way as a stale GET: auto-swap silently, or surface a clear
                // "your unit was just taken" message in manual mode.
                return $this->handleStorageNoLongerAvailable($place, $storageType, $storage, $formData);
            }

            $this->logger->error('Order creation failed during acceptance', [
                'place_id' => $place->id->toRfc4122(),
                'storage_id' => $storage->id->toRfc4122(),
                'exception' => $exception,
            ]);
            $this->addFlash('error', 'Při vytváření objednávky došlo k chybě. Zkuste to prosím znovu.');

            $paymentSchedule = $this->priceCalculator->buildPaymentSchedule($storage, $startDate, $formData->endDate);

            return $this->render('public/order_accept.html.twig', [
                'formData' => $formData,
                'storage' => $storage,
                'storageType' => $storageType,
                'place' => $place,
                'paymentSchedule' => $paymentSchedule,
                'isRecurring' => $paymentSchedule->isRecurring,
                'requiresEarlyStartWaiver' => $requiresEarlyStartWaiver,
                'recurringPaymentLegalMaxInCzk' => intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100),
                'submitted' => self::emptySubmittedValues(),
                'now' => $now,
            ]);
        }
    }
}
