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
     * Server-rendered pricing preview shown above the submit button. Uses the same
     * {@see PriceCalculator} that the order acceptance flow uses, so what the
     * customer sees here is exactly what they'll pay.
     *
     * @return array{type: 'monthly', monthly: float}
     *               |array{type: 'oneTime', days: int, total: float}
     *               |array{type: 'recurring', days: int, total: float, monthly: float}
     *               |null Null when the form data is incomplete (e.g. missing dates).
     */
    public function getPricing(Storage $storage): ?array
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OrderFormData) {
            return null;
        }

        $monthlyCzk = $storage->getEffectivePricePerMonthInCzk();

        if (RentalType::UNLIMITED === $data->rentalType) {
            return ['type' => 'monthly', 'monthly' => $monthlyCzk];
        }

        if (null === $data->startDate || null === $data->endDate) {
            return null;
        }

        $days = (int) $data->startDate->diff($data->endDate)->days;
        if ($days <= 0) {
            return null;
        }

        $totalCzk = $this->priceCalculator->calculatePriceForStorage($storage, $data->startDate, $data->endDate) / 100;

        if ($days < 28) {
            return ['type' => 'oneTime', 'days' => $days, 'total' => $totalCzk];
        }

        return ['type' => 'recurring', 'days' => $days, 'total' => $totalCzk, 'monthly' => $monthlyCzk];
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

        $this->requestStack->getSession()->set('order_form_data', $data->toSessionArray());

        return new RedirectResponse($this->urlGenerator->generate('public_order_accept', [
            'placeId' => $this->place->id->toRfc4122(),
            'storageTypeId' => $this->storageType->id->toRfc4122(),
            'storageId' => $this->storageId,
        ]));
    }
}
