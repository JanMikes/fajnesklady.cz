<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Command\AdminOnboardingCommand;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Form\AdminOnboardingFormData;
use App\Form\AdminOnboardingFormType;
use App\Repository\PlaceRepository;
use App\Repository\PlatformSettingsRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\PriceCalculator;
use App\Service\StorageAvailabilityChecker;
use App\Value\OnboardingSchedulePreview;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class AdminOnboardingForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp(writable: true)]
    public ?string $placeId = null;

    #[LiveProp(writable: true)]
    public ?string $storageTypeId = null;

    #[LiveProp(writable: true)]
    public ?string $storageId = null;

    #[LiveProp]
    public ?string $submitError = null;

    /**
     * Surfaced next to the place/storage selection when the admin submits
     * without having picked a storage unit (or place/type). Rendered as a
     * [data-live-error] anchor so live-form-scroll lands the page on it.
     */
    #[LiveProp]
    public ?string $storageError = null;

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly PriceCalculator $priceCalculator,
        private readonly MessageBusInterface $commandBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly PlatformSettingsRepository $platformSettingsRepository,
        private readonly StorageAvailabilityChecker $availabilityChecker,
    ) {
    }

    public function getBankTransferSurchargeInCzk(): float
    {
        return $this->platformSettingsRepository->getSettings()->getBankTransferSurchargeInCzk();
    }

    /**
     * @return FormInterface<AdminOnboardingFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(AdminOnboardingFormType::class, new AdminOnboardingFormData());
    }

    /**
     * @return Place[]
     */
    public function getPlaces(): array
    {
        return $this->placeRepository->findAllActive();
    }

    /**
     * @return StorageType[]
     */
    public function getStorageTypes(): array
    {
        $place = $this->getSelectedPlace();
        if (null === $place) {
            return [];
        }

        return $this->storageTypeRepository->findByPlace($place);
    }

    public function getSelectedPlace(): ?Place
    {
        if (null === $this->placeId || '' === $this->placeId) {
            return null;
        }

        if (!Uuid::isValid($this->placeId)) {
            return null;
        }

        return $this->placeRepository->find(Uuid::fromString($this->placeId));
    }

    public function getSelectedStorageType(): ?StorageType
    {
        if (null === $this->storageTypeId || '' === $this->storageTypeId) {
            return null;
        }

        if (!Uuid::isValid($this->storageTypeId)) {
            return null;
        }

        return $this->storageTypeRepository->find(Uuid::fromString($this->storageTypeId));
    }

    public function getSelectedStorage(): ?Storage
    {
        if (null === $this->storageId || '' === $this->storageId) {
            return null;
        }

        if (!Uuid::isValid($this->storageId)) {
            return null;
        }

        return $this->storageRepository->find(Uuid::fromString($this->storageId));
    }

    /**
     * The rental window the admin has currently chosen, or null when it is not
     * yet usable (no start date, or no valid end after it). Drives the same
     * availability check the order-acceptance enforcement uses.
     *
     * Read from the live {@see $formValues} (the admin's current field values),
     * NOT getForm()->getData(): a LiveAction such as selectStorage runs BEFORE
     * the #[PreReRender] form submit, so getData() would still hold the freshly
     * instantiated (empty) model, not the dates just entered.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    private function resolveWindow(): ?array
    {
        $start = $this->parseFormDate($this->formValues['startDate'] ?? null);
        if (null === $start) {
            return null;
        }

        $end = $this->parseFormDate($this->formValues['endDate'] ?? null);
        if (null === $end || $end <= $start) {
            return null;
        }

        return [$start, $end];
    }

    private function parseFormDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        // Date fields render as single_text (HTML5 date) → 'Y-m-d'.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $date ? null : $date;
    }

    /**
     * Whether the admin has chosen enough of the rental window for the map to
     * show real availability. Until then the map greys out and prompts for dates.
     */
    public function hasValidWindow(): bool
    {
        return null !== $this->resolveWindow();
    }

    public function getStoragesJson(): string
    {
        $place = $this->getSelectedPlace();
        $storageType = $this->getSelectedStorageType();

        if (null === $place || null === $storageType) {
            return '[]';
        }

        $storages = $this->storageRepository->findByPlace($place);
        $window = $this->resolveWindow();
        $availability = null === $window
            ? []
            : $this->availabilityChecker->availabilityForStorages($storages, $window[0], $window[1]);

        $payload = [];

        foreach ($storages as $storage) {
            $key = $storage->id->toRfc4122();
            $available = $availability[$key] ?? null;
            $payload[] = [
                'id' => $key,
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
                'storageTypeName' => $storage->storageType->name,
                'dimensions' => $storage->storageType->getDimensionsInMeters(),
                'coordinates' => $storage->coordinates,
                'status' => null !== $available ? $available->derivedStatus->value : $storage->status->value,
                'available' => null !== $available && $available->isAvailable,
                'lockCode' => $storage->lockCode,
                'tenantName' => null,
                'rentedFrom' => null,
                'rentedUntil' => null,
                'hasGuarantee' => false,
                'isTerminating' => false,
                'startsOnViewDate' => false,
                'endsOnViewDate' => false,
                'orderUrl' => null,
                'photoUrls' => [],
                'pricePerMonth' => $storage->getEffectivePricePerMonthInCzk(),
                'pricePerMonthLongTerm' => $storage->getEffectivePricePerMonthLongTermInCzk(),
                'pricePerWeek' => $storage->getEffectivePricePerWeekInCzk(),
                'isUniform' => $storage->storageType->uniformStorages,
            ];
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * The "Kalkulace plateb" card content, computed from the CURRENT form
     * values so the table always mirrors the contract submit() would create:
     * pricing mode (standardní / individuální / zdarma), payment frequency
     * (GoPay is already normalised to MONTHLY by the form's PRE_SUBMIT hook)
     * and external prepayment. Null until a storage unit and a valid rental
     * window are chosen — the card stays hidden.
     */
    public function getSchedulePreview(): ?OnboardingSchedulePreview
    {
        $storage = $this->getSelectedStorage();
        if (null === $storage) {
            return null;
        }

        $data = $this->getForm()->getData();
        if (!$data instanceof AdminOnboardingFormData) {
            return null;
        }

        $startDate = $data->startDate;
        $endDate = $data->endDate;
        if (null === $startDate || null === $endDate || $endDate <= $startDate) {
            return null;
        }

        if ('free' === $data->monthlyPriceMode) {
            return new OnboardingSchedulePreview(schedule: null, isFree: true);
        }

        $frequency = $data->paymentFrequency ?? PaymentFrequency::MONTHLY;
        $customPriceFallback = $this->resolveCustomPriceFallback($data, $frequency, $startDate, $endDate);
        $customRate = 'custom' === $data->monthlyPriceMode && null === $customPriceFallback && null !== $data->customMonthlyPriceInCzk
            ? (int) round($data->customMonthlyPriceInCzk * 100)
            : null;

        // Mirrors submit(): the paid-through date binds when external
        // prepayment is on, or a backdated start forces it ('free' returned above).
        $prepaidUntil = ($data->isExternallyPrepaid || $data->startsInPast()) ? $data->paidThroughDate : null;

        if (null !== $prepaidUntil && $prepaidUntil >= $endDate) {
            return new OnboardingSchedulePreview(schedule: null, isFullyPrepaid: true, prepaidUntil: $prepaidUntil);
        }

        if (null !== $prepaidUntil && $prepaidUntil > $startDate) {
            $periodRate = $this->resolveLockedPeriodRate($storage, $startDate, $endDate, $frequency, $customRate);

            if (null !== $periodRate) {
                $schedule = $this->priceCalculator->buildScheduleFromBillingAnchor($periodRate, $frequency, $prepaidUntil, $endDate);

                return new OnboardingSchedulePreview(
                    schedule: $schedule->isEmpty() ? null : $schedule,
                    prepaidUntil: $prepaidUntil,
                    isAnchoredAtPaidThrough: true,
                    customPriceFallback: $customPriceFallback,
                );
            }
        }

        $schedule = null !== $customRate
            ? $this->priceCalculator->buildScheduleFromRate($customRate, $frequency, $startDate, $endDate)
            : $this->priceCalculator->buildPaymentSchedule($storage, $startDate, $endDate, $frequency);

        return new OnboardingSchedulePreview(
            schedule: $schedule->isEmpty() ? null : $schedule,
            prepaidUntil: $prepaidUntil,
            customPriceFallback: $customPriceFallback,
        );
    }

    /**
     * Why the entered individual price cannot drive the preview (and the
     * created contract): 'missing' — custom mode without a usable amount yet;
     * 'upfront_tranches' — an upfront rental longer than 12 monthly periods
     * pays in yearly tranches derived from the price list, a custom total is
     * rejected by validation (see AdminOnboardingFormData). Null = applied.
     */
    private function resolveCustomPriceFallback(
        AdminOnboardingFormData $data,
        PaymentFrequency $frequency,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): ?string {
        if ('custom' !== $data->monthlyPriceMode) {
            return null;
        }

        if (null === $data->customMonthlyPriceInCzk || $data->customMonthlyPriceInCzk <= 0) {
            return 'missing';
        }

        if (PaymentFrequency::ONE_TIME === $frequency && PriceCalculator::isUpfrontSplitIntoTranches($startDate, $endDate)) {
            return 'upfront_tranches';
        }

        return null;
    }

    /**
     * The locked per-period rate the recurring billing would walk from the
     * paid-through anchor — the monthly figure for MONTHLY and for ONE_TIME
     * tranches, the yearly figure for YEARLY. The tier follows the FULL rental
     * window (that is what gets locked into the order), never the remaining
     * stub. Null when there is no per-period rate to walk: sub-31-day rentals
     * are a single weekly-priced payment, and a custom ONE_TIME price is a
     * whole-rental total — those fall back to the unshifted schedule.
     */
    private function resolveLockedPeriodRate(
        Storage $storage,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        PaymentFrequency $frequency,
        ?int $customRate,
    ): ?int {
        $rateType = $this->priceCalculator->resolveRateType($frequency, $startDate, $endDate);
        if ('weekly' === $rateType) {
            return null;
        }

        if (null !== $customRate) {
            return PaymentFrequency::ONE_TIME === $frequency ? null : $customRate;
        }

        return match ($rateType) {
            'yearly' => $storage->getEffectivePricePerYear(),
            'monthly_short' => $storage->getEffectivePricePerMonth(),
            'monthly_long' => $storage->getEffectivePricePerMonthLongTerm(),
        };
    }

    /**
     * Whether the currently chosen window would split an upfront (ONE_TIME)
     * payment into yearly tranches (spec 078) — drives the per-option hint
     * under the "Jednorázová platba předem" radio.
     */
    public function isUpfrontSplitIntoTranches(): bool
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof AdminOnboardingFormData) {
            return false;
        }

        if (null === $data->startDate || null === $data->endDate || $data->endDate <= $data->startDate) {
            return false;
        }

        return PriceCalculator::isUpfrontSplitIntoTranches($data->startDate, $data->endDate);
    }

    #[LiveAction]
    public function selectStorage(#[LiveArg] string $storageId): void
    {
        $this->storageError = null;

        if (!Uuid::isValid($storageId)) {
            return;
        }

        $candidate = $this->storageRepository->find(Uuid::fromString($storageId));
        if (null === $candidate) {
            return;
        }

        $storageType = $this->getSelectedStorageType();
        if (null === $storageType || !$candidate->storageType->id->equals($storageType->id)) {
            return;
        }

        // Hard block: onboarding must never assign a unit that is occupied or
        // blocked for the chosen window — renting the same unit twice is always a
        // mistake. Same date-range checker the order-acceptance enforcement uses.
        $window = $this->resolveWindow();
        if (null === $window) {
            $this->storageError = 'Nejdříve zvolte termín pronájmu (datum začátku i konce).';

            return;
        }

        if (!$this->availabilityChecker->isAvailable($candidate, $window[0], $window[1])) {
            $this->storageError = 'Tato skladová jednotka je ve zvoleném období obsazená nebo blokovaná. Vyberte jinou.';

            return;
        }

        $this->storageId = $storageId;
    }

    #[LiveAction]
    public function onPlaceChange(): void
    {
        $this->storageTypeId = null;
        $this->storageId = null;
    }

    #[LiveAction]
    public function onStorageTypeChange(): void
    {
        $this->storageId = null;
    }

    #[LiveAction]
    public function validateField(#[LiveArg] string $field): void
    {
        $fieldPath = $this->getFormName().'.'.$field;

        if (!in_array($fieldPath, $this->validatedFields, true)) {
            $this->validatedFields[] = $fieldPath;
        }
    }

    #[LiveAction]
    public function submit(): ?RedirectResponse
    {
        $this->submitError = null;
        $this->storageError = null;
        $this->submitForm();

        $form = $this->getForm();
        if (!$form->isValid()) {
            return null;
        }

        /** @var AdminOnboardingFormData $formData */
        $formData = $form->getData();
        \assert(null !== $formData->startDate);
        \assert(null !== $formData->endDate);
        \assert(null !== $formData->paymentMethod);
        \assert(null !== $formData->billingMode);
        \assert(null !== $formData->paymentFrequency);

        $storage = $this->getSelectedStorage();
        if (null === $storage) {
            $this->storageError = 'Vyberte skladovou jednotku z mapy.';

            return null;
        }

        $storageType = $this->getSelectedStorageType();
        $place = $this->getSelectedPlace();
        if (null === $storageType || null === $place) {
            $this->storageError = 'Vyberte pobočku a typ skladové jednotky.';

            return null;
        }

        $individualMonthlyAmount = match ($formData->monthlyPriceMode) {
            'custom' => null !== $formData->customMonthlyPriceInCzk ? (int) round($formData->customMonthlyPriceInCzk * 100) : null,
            'free' => 0,
            default => null,
        };
        $paidThroughDate = ($formData->isExternallyPrepaid
            || ($formData->startsInPast() && 'free' !== $formData->monthlyPriceMode))
            ? $formData->paidThroughDate
            : null;

        $uploadedContractPath = null;
        if (null !== $formData->contractDocument) {
            $uploadedContractPath = $formData->contractDocument->move(
                sys_get_temp_dir(),
                uniqid('contract_', true).'.'.$formData->contractDocument->guessExtension(),
            )->getPathname();
        }

        $admin = $this->getUser();
        \assert($admin instanceof User);

        $debtInHaler = null !== $formData->debtAmountInCzk && $formData->debtAmountInCzk > 0
            ? (int) round($formData->debtAmountInCzk * 100)
            : null;

        try {
            $envelope = $this->commandBus->dispatch(new AdminOnboardingCommand(
                email: $formData->email,
                firstName: $formData->firstName,
                lastName: $formData->lastName,
                phone: $formData->phone,
                birthDate: $formData->birthDate,
                companyName: $formData->invoiceToCompany ? $formData->companyName : null,
                companyId: $formData->invoiceToCompany ? $formData->companyId : null,
                companyVatId: $formData->invoiceToCompany ? $formData->companyVatId : null,
                billingStreet: $formData->billingStreet ?? '',
                billingCity: $formData->billingCity ?? '',
                billingPostalCode: $formData->billingPostalCode ?? '',
                storage: $storage,
                storageType: $storageType,
                place: $place,
                startDate: $formData->startDate,
                endDate: $formData->endDate,
                paymentMethod: $formData->paymentMethod,
                individualMonthlyAmount: $individualMonthlyAmount,
                paidThroughDate: $paidThroughDate,
                createdByAdminId: $admin->id,
                billingMode: $formData->billingMode,
                paymentFrequency: $formData->paymentFrequency,
                variableSymbolOverride: $formData->variableSymbol,
                uploadedContractPath: $uploadedContractPath,
                debtInHaler: $debtInHaler,
            ));

            $handledStamp = $envelope->last(HandledStamp::class);
            $order = $handledStamp?->getResult();

            if ($order instanceof Order && null !== $order->signingToken) {
                $signingUrl = $this->urlGenerator->generate(
                    'public_customer_signing',
                    ['token' => $order->signingToken],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );

                $this->addFlash('success', sprintf(
                    'Onboarding pro %s %s byl vytvořen. Odkaz k podpisu byl odeslán na %s.',
                    $formData->firstName,
                    $formData->lastName,
                    $formData->email,
                ));
                $this->addFlash('info', sprintf('Odkaz k podpisu: %s', $signingUrl));
            }
        } catch (\Throwable $rawException) {
            $exception = HandlerFailureUnwrap::unwrap($rawException);

            if ($exception instanceof \DomainException) {
                $this->submitError = 'Při vytváření onboardingu došlo k chybě: '.$exception->getMessage();
            } else {
                $this->logger->error('Admin onboarding creation failed', ['exception' => $exception]);
                $this->submitError = 'Při vytváření onboardingu došlo k chybě. Zkuste to prosím znovu.';
            }

            return null;
        }

        return new RedirectResponse($this->urlGenerator->generate('admin_orders_list'));
    }
}
