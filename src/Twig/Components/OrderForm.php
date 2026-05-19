<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Form\OrderFormType;
use App\Repository\StorageRepository;
use App\Service\PriceCalculator;
use App\Value\PaymentSchedule;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class OrderForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public Place $place;

    #[LiveProp]
    public StorageType $storageType;

    #[LiveProp]
    public string $storageId = '';

    /**
     * Reflects the "auto vs. pick from map" toggle on the page. Drives the sidebar
     * label ("Bude vybráno automaticky" vs. the concrete storage number) so the
     * customer sees the same intent the order will carry forward.
     *
     * Writable so the radios bind to it via `data-model` directly. The form template
     * only renders the two valid values ('auto' / 'manual'); a hostile client could
     * still submit anything, but the worst case is a value that fails the
     * `isAutoSelection` Twig comparison and falls through to the "manual" branch.
     */
    #[LiveProp(writable: true)]
    public string $selectionMode = 'auto';

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PriceCalculator $priceCalculator,
    ) {
    }

    /**
     * @return FormInterface<OrderFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $session = $this->requestStack->getSession();
        $sessionData = $session->get('order_form_data');

        if (is_array($sessionData)) {
            $formData = OrderFormData::fromSessionArray($sessionData);
        } elseif (($user = $this->getUser()) instanceof User) {
            $formData = OrderFormData::fromUser($user);
        } else {
            $formData = new OrderFormData();
        }

        // startDate is intentionally left unset — the user must pick one. Validation in
        // OrderFormData::validateDates enforces this.
        return $this->createForm(OrderFormType::class, $formData);
    }

    public function getSelectedStorage(): Storage
    {
        $storage = Uuid::isValid($this->storageId)
            ? $this->storageRepository->find(Uuid::fromString($this->storageId))
            : null;

        if (null === $storage) {
            throw $this->createNotFoundException('Skladová jednotka nenalezena.');
        }

        return $storage;
    }

    /**
     * Whether the customer is currently eligible to choose between AUTO and
     * MANUAL recurring billing. Only fixed-term LIMITED rentals of ≥ 28 days
     * surface the radio — short LIMITED is forced ONE_TIME, UNLIMITED is
     * forced AUTO. Re-evaluated reactively on every date change.
     */
    public function isEligibleForBillingModeChoice(): bool
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return false;
        }

        if (RentalType::LIMITED !== $data->rentalType) {
            return false;
        }

        if (null === $data->startDate || null === $data->endDate) {
            return false;
        }

        return (int) $data->startDate->diff($data->endDate)->days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
    }

    /**
     * Which storage rate will actually be charged given the customer's current
     * selections. Mirrors {@see PriceCalculator::buildPaymentSchedule()} cutover:
     *
     *   - UNLIMITED                          → 'monthly'
     *   - LIMITED, days < 28                 → 'weekly'
     *   - LIMITED, days >= 28                → 'monthly'
     *   - LIMITED, dates missing or invalid  → null (undecided — show both)
     *
     * The Ceník sidebar collapses to the single applicable row when this
     * returns a string, and falls back to "both rates + explainer" on null.
     *
     * @return 'weekly'|'monthly'|null
     */
    public function getApplicableRate(): ?string
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return null;
        }

        if (RentalType::UNLIMITED === $data->rentalType) {
            return 'monthly';
        }

        if (null === $data->startDate || null === $data->endDate) {
            return null;
        }

        $days = (int) $data->startDate->diff($data->endDate)->days;
        if ($days <= 0) {
            return null;
        }

        return $days < PriceCalculator::WEEKLY_THRESHOLD_DAYS ? 'weekly' : 'monthly';
    }

    /**
     * Authoritative payment schedule for the live preview. Same
     * {@see PriceCalculator::buildPaymentSchedule()} call the order_accept
     * page and the recurring-billing cron use — what's shown here is what
     * the customer will actually be charged.
     *
     * Returns null when the form data is incomplete (e.g. missing dates) so
     * the template can hide the preview block cleanly.
     */
    public function getPaymentSchedule(Storage $storage): ?PaymentSchedule
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return null;
        }

        if (RentalType::UNLIMITED === $data->rentalType) {
            $startDate = $data->startDate ?? new \DateTimeImmutable('today');

            return $this->priceCalculator->buildPaymentSchedule($storage, $startDate, null);
        }

        if (null === $data->startDate || null === $data->endDate) {
            return null;
        }

        $schedule = $this->priceCalculator->buildPaymentSchedule($storage, $data->startDate, $data->endDate);

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

        if (!$candidate->place->id->equals($this->place->id)) {
            return;
        }

        if (!$candidate->storageType->id->equals($this->storageType->id)) {
            return;
        }

        if (!$candidate->isAvailable()) {
            return;
        }

        $this->storageId = $storageId;
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
    public function submit(): RedirectResponse
    {
        $this->submitForm();

        /** @var OrderFormData $data */
        $data = $this->getForm()->getData();

        if (RentalType::UNLIMITED === $data->rentalType) {
            $data->endDate = null;
        }

        // Carry the auto/manual toggle into session so OrderAcceptController can decide
        // between silent re-assignment ('auto') and a "this unit was just taken" error ('manual').
        $data->selectionMode = 'manual' === $this->selectionMode ? 'manual' : 'auto';

        $this->requestStack->getSession()->set('order_form_data', $data->toSessionArray());

        return new RedirectResponse($this->urlGenerator->generate('public_order_accept', [
            'placeId' => $this->place->id->toRfc4122(),
            'storageTypeId' => $this->storageType->id->toRfc4122(),
            'storageId' => $this->storageId,
        ]));
    }
}
