<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Form\OrderFormData;
use App\Form\OrderFormType;
use App\Repository\PlatformSettingsRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use App\Service\PriceCalculator;
use App\Service\StorageAvailabilityChecker;
use App\Value\PaymentSchedule;
use Psr\Clock\ClockInterface;
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

    #[LiveProp]
    public bool $emailExistsInSystem = false;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly UserRepository $userRepository,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PriceCalculator $priceCalculator,
        private readonly PlatformSettingsRepository $platformSettingsRepository,
        private readonly StorageAvailabilityChecker $availabilityChecker,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getBankTransferSurchargeInCzk(): float
    {
        return $this->platformSettingsRepository->getSettings()->getBankTransferSurchargeInCzk();
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
     * The rental window the customer has currently chosen, or null when it is
     * not yet usable (no start date, or no valid end after it). This is the
     * SAME window the availability map, the select guard, and order-acceptance
     * enforcement all key on.
     *
     * Read from the live {@see $formValues} (the client's current field values),
     * NOT getForm()->getData(): a LiveAction such as selectStorage runs BEFORE
     * the #[PreReRender] form submit, so getData() would still hold the
     * session-hydrated model, not the dates the customer just typed. Mirrors the
     * existing formValues access in {@see self::validateField()}.
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
     * Whether the customer has chosen enough of the rental window for the map to
     * show real availability. Until then the map greys out and prompts for dates.
     */
    public function hasValidWindow(): bool
    {
        return null !== $this->resolveWindow();
    }

    /**
     * Map payload for every storage at this place, with per-storage availability
     * derived for the chosen window via the SAME {@see StorageAvailabilityChecker}
     * enforcement uses. Re-rendered by the Live Component on each rental-type /
     * date change, so the map repaints reactively. Until a valid window exists,
     * every unit is reported unavailable (the template greys the map + prompts).
     */
    public function getStoragesJson(): string
    {
        $storages = $this->storageRepository->findByPlace($this->place);
        $window = $this->resolveWindow();
        $availability = null === $window
            ? []
            : $this->availabilityChecker->availabilityForStorages($storages, $window[0], $window[1]);
        // Spec 084: manual map picks are limited to "clean" units — nothing
        // booked anywhere in [today, ∞) — so engaged-but-free units stay
        // reachable only through auto-assignment.
        $clean = null === $window
            ? []
            : $this->availabilityChecker->cleanForStorages($storages, $this->clock->now());

        $payload = [];
        foreach ($storages as $storage) {
            $key = $storage->id->toRfc4122();
            $available = $availability[$key] ?? null;
            $payload[] = [
                'id' => $key,
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
                'storageTypeName' => $storage->storageType->name,
                'coordinates' => $storage->coordinates,
                'dimensions' => $storage->storageType->getDimensionsInMeters(),
                'status' => null !== $available ? $available->derivedStatus->value : $storage->status->value,
                'available' => null !== $available && $available->isAvailable,
                'selectable' => null !== $available && $available->isAvailable && ($clean[$key] ?? false),
                'pricePerWeek' => $storage->getEffectivePricePerWeekInCzk(),
                'pricePerMonth' => $storage->getEffectivePricePerMonthInCzk(),
                'isUniform' => $storage->storageType->uniformStorages,
                // Unit-specific photos first ("show me this exact unit"), then the
                // generic storage-type photos ("…and what others of this type look like").
                'photoUrls' => array_merge(
                    array_map(static fn ($p) => '/uploads/'.$p->path, $storage->getPhotos()->toArray()),
                    array_map(static fn ($p) => '/uploads/'.$p->path, $storage->storageType->getPhotos()->toArray()),
                ),
            ];
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Whether the card option is available for the chosen window: spec 076
     * allows cards only for recurring monthly billing, which needs a rental of
     * at least {@see PriceCalculator::WEEKLY_THRESHOLD_DAYS} days. Returns true
     * while the dates are missing/invalid (nothing to judge yet — the payment
     * section shouldn't flash a warning before the customer picked a window);
     * server-side {@see OrderFormData::validatePaymentMethod()} is the backstop.
     */
    public function isCardEligible(): bool
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return true;
        }

        if (null === $data->startDate || null === $data->endDate || $data->endDate <= $data->startDate) {
            return true;
        }

        return (int) $data->startDate->diff($data->endDate)->days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
    }

    /**
     * Whether the customer is currently eligible for the YEARLY frequency
     * choice — needs at least {@see PriceCalculator::YEARLY_THRESHOLD_DAYS}
     * days (spec 045).
     */
    public function isEligibleForFrequencyChoice(): bool
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return false;
        }

        if (null === $data->startDate || null === $data->endDate) {
            return false;
        }

        return (int) $data->startDate->diff($data->endDate)->days >= PriceCalculator::YEARLY_THRESHOLD_DAYS;
    }

    /**
     * Whether the customer is currently eligible for the ONE_TIME (whole
     * rental upfront) frequency choice — needs at least
     * {@see PriceCalculator::WEEKLY_THRESHOLD_DAYS} days (spec 078); shorter
     * rentals are whole-amount one-shots by construction already.
     */
    public function isEligibleForUpfrontChoice(): bool
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return false;
        }

        if (null === $data->startDate || null === $data->endDate) {
            return false;
        }

        return (int) $data->startDate->diff($data->endDate)->days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
    }

    /**
     * Whether the currently chosen window would split an upfront (ONE_TIME)
     * payment into yearly tranches (spec 078) — drives the per-option hint
     * under the "Jednorázová platba předem" radio.
     */
    public function isUpfrontSplitIntoTranches(): bool
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return false;
        }

        if (null === $data->startDate || null === $data->endDate || $data->endDate <= $data->startDate) {
            return false;
        }

        return PriceCalculator::isUpfrontSplitIntoTranches($data->startDate, $data->endDate);
    }

    /**
     * @return 'weekly'|'monthly_short'|'monthly_long'|'yearly'|null
     */
    public function getApplicableRate(): ?string
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return null;
        }

        if (PaymentFrequency::YEARLY === $data->paymentFrequency && $this->isEligibleForFrequencyChoice()) {
            return 'yearly';
        }

        if (null === $data->startDate || null === $data->endDate) {
            return null;
        }

        $days = (int) $data->startDate->diff($data->endDate)->days;
        if ($days <= 0) {
            return null;
        }

        if ($days < PriceCalculator::WEEKLY_THRESHOLD_DAYS) {
            return 'weekly';
        }

        return $days < PriceCalculator::SHORT_TERM_THRESHOLD_DAYS ? 'monthly_short' : 'monthly_long';
    }

    /**
     * Customer-facing "X Kč / měsíc" equivalent for the currently selected
     * storage's yearly rate. The spec 045 user brief is literal: "I want to
     * see how much it will cost me per month, not per year".
     */
    public function getYearlyMonthlyEquivalentInCzk(Storage $storage): float
    {
        return $storage->getEffectivePricePerYear() / 12 / 100;
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

        $frequency = PaymentFrequency::MONTHLY;
        if (PaymentFrequency::YEARLY === $data->paymentFrequency && $this->isEligibleForFrequencyChoice()) {
            $frequency = PaymentFrequency::YEARLY;
        } elseif (PaymentFrequency::ONE_TIME === $data->paymentFrequency && $this->isEligibleForUpfrontChoice()) {
            $frequency = PaymentFrequency::ONE_TIME;
        }

        if (null === $data->startDate || null === $data->endDate) {
            return null;
        }

        $schedule = $this->priceCalculator->buildPaymentSchedule($storage, $data->startDate, $data->endDate, $frequency);

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

        // Gate on the SAME date-range check enforcement uses — not the mutable
        // Storage::status enum, which drifts. No window chosen yet → no selection.
        $window = $this->resolveWindow();
        if (null === $window) {
            return;
        }

        if (!$this->availabilityChecker->isAvailable($candidate, $window[0], $window[1])) {
            return;
        }

        // Spec 084: manual picks require a clean unit (no engagement in
        // [today, ∞)) so a stranger can't hijack a sitting tenant's likely
        // prolongation. Engaged-but-free units remain auto-assign only.
        if (!$this->availabilityChecker->isClean($candidate, $this->clock->now())) {
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

        if ('email' === $field) {
            $email = trim((string) ($this->formValues['email'] ?? ''));
            $this->emailExistsInSystem = '' !== $email && null !== $this->userRepository->findByEmail($email);
        }
    }

    #[LiveAction]
    public function submit(): RedirectResponse
    {
        $this->submitForm();

        /** @var OrderFormData $data */
        $data = $this->getForm()->getData();

        if ($this->emailExistsInSystem) {
            $data->plainPassword = null;
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
