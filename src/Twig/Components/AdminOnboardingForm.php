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
use App\Value\PaymentSchedule;
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

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly PriceCalculator $priceCalculator,
        private readonly MessageBusInterface $commandBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly PlatformSettingsRepository $platformSettingsRepository,
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

    public function getStoragesJson(): string
    {
        $place = $this->getSelectedPlace();
        $storageType = $this->getSelectedStorageType();

        if (null === $place || null === $storageType) {
            return '[]';
        }

        $storages = $this->storageRepository->findByPlace($place);
        $payload = [];

        foreach ($storages as $storage) {
            $payload[] = [
                'id' => $storage->id->toRfc4122(),
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
                'storageTypeName' => $storage->storageType->name,
                'dimensions' => $storage->storageType->getDimensionsInMeters(),
                'coordinates' => $storage->coordinates,
                'status' => $storage->status->value,
                'lockCode' => $storage->lockCode,
                'tenantName' => null,
                'rentedFrom' => null,
                'rentedUntil' => null,
                'isUnlimited' => false,
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

    public function getPaymentSchedule(): ?PaymentSchedule
    {
        $storage = $this->getSelectedStorage();
        if (null === $storage) {
            return null;
        }

        $data = $this->getForm()->getData();
        if (!$data instanceof AdminOnboardingFormData) {
            return null;
        }

        if (null === $data->startDate || null === $data->rentalType) {
            return null;
        }

        $schedule = $this->priceCalculator->buildPaymentSchedule(
            $storage,
            $data->startDate,
            $data->endDate,
            $data->paymentFrequency ?? PaymentFrequency::MONTHLY,
        );

        return $schedule->isEmpty() ? null : $schedule;
    }

    #[LiveAction]
    public function selectStorage(#[LiveArg] string $storageId): void
    {
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
        $this->submitForm();

        $form = $this->getForm();
        if (!$form->isValid()) {
            return null;
        }

        /** @var AdminOnboardingFormData $formData */
        $formData = $form->getData();
        \assert(null !== $formData->startDate);
        \assert(null !== $formData->rentalType);
        \assert(null !== $formData->paymentMethod);
        \assert(null !== $formData->billingMode);
        \assert(null !== $formData->paymentFrequency);

        $storage = $this->getSelectedStorage();
        if (null === $storage) {
            $this->submitError = 'Vyberte skladovou jednotku z mapy.';

            return null;
        }

        $storageType = $this->getSelectedStorageType();
        $place = $this->getSelectedPlace();
        if (null === $storageType || null === $place) {
            $this->submitError = 'Vyberte pobočku a typ skladové jednotky.';

            return null;
        }

        $individualMonthlyAmount = match ($formData->monthlyPriceMode) {
            'custom' => null !== $formData->customMonthlyPriceInCzk ? (int) round($formData->customMonthlyPriceInCzk * 100) : null,
            'free' => 0,
            default => null,
        };
        $paidThroughDate = $formData->isExternallyPrepaid ? $formData->paidThroughDate : null;

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
                rentalType: $formData->rentalType,
                startDate: $formData->startDate,
                endDate: $formData->endDate,
                paymentMethod: $formData->paymentMethod,
                individualMonthlyAmount: $individualMonthlyAmount,
                paidThroughDate: $paidThroughDate,
                createdByAdminId: $admin->id,
                billingMode: $formData->billingMode,
                expectedDuration: $formData->expectedDuration,
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
