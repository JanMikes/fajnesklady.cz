<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Command\ChooseOnboardingPaymentCommand;
use App\Entity\Order;
use App\Enum\PaymentFrequency;
use App\Form\OnboardingPaymentChoiceFormData;
use App\Form\OnboardingPaymentChoiceFormType;
use App\Repository\OrderRepository;
use App\Repository\PlatformSettingsRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\PriceCalculator;
use App\Value\PaymentSchedule;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class OnboardingPaymentChoiceForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public string $token = '';

    #[LiveProp]
    public ?string $submitError = null;

    private ?Order $resolvedOrder = null;

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PriceCalculator $priceCalculator,
        private readonly PlatformSettingsRepository $platformSettingsRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getOrder(): Order
    {
        if (null === $this->resolvedOrder) {
            $order = $this->orderRepository->findBySigningToken($this->token);
            if (null === $order || !$order->canEditPaymentChoice()) {
                throw new NotFoundHttpException('Objednávka nenalezena nebo již není možné zvolit platbu.');
            }
            $this->resolvedOrder = $order;
        }

        return $this->resolvedOrder;
    }

    public function getRentalDays(): int
    {
        $order = $this->getOrder();
        \assert(null !== $order->endDate);

        return (int) $order->startDate->diff($order->endDate)->days;
    }

    public function isCardEligible(): bool
    {
        return $this->getRentalDays() >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
    }

    public function isEligibleForFrequencyChoice(): bool
    {
        return $this->getRentalDays() >= PriceCalculator::YEARLY_THRESHOLD_DAYS;
    }

    public function isEligibleForUpfrontChoice(): bool
    {
        return $this->getRentalDays() >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
    }

    public function getBankTransferSurchargeInCzk(): float
    {
        return $this->platformSettingsRepository->getSettings()->getBankTransferSurchargeInCzk();
    }

    /**
     * @return FormInterface<OnboardingPaymentChoiceFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $formData = new OnboardingPaymentChoiceFormData();
        // rentalDays is not a form field, so the client submit never overwrites
        // it — set once so the matrix validation always has the fixed window.
        $formData->rentalDays = $this->getRentalDays();

        return $this->createForm(OnboardingPaymentChoiceFormType::class, $formData, [
            'rental_days' => $this->getRentalDays(),
        ]);
    }

    /**
     * Live price preview from the SAME PriceCalculator the payment page + cron
     * use — what's shown is what the customer will actually be charged. Null
     * until a method is chosen (or the schedule is empty).
     */
    public function getPaymentSchedule(): ?PaymentSchedule
    {
        $data = $this->getForm()->getData();
        if (!$data instanceof OnboardingPaymentChoiceFormData || null === $data->paymentMethod) {
            return null;
        }

        $order = $this->getOrder();
        \assert(null !== $order->endDate);

        $frequency = PaymentFrequency::MONTHLY;
        if (PaymentFrequency::YEARLY === $data->paymentFrequency && $this->isEligibleForFrequencyChoice()) {
            $frequency = PaymentFrequency::YEARLY;
        } elseif (PaymentFrequency::ONE_TIME === $data->paymentFrequency && $this->isEligibleForUpfrontChoice()) {
            $frequency = PaymentFrequency::ONE_TIME;
        }

        $schedule = $this->priceCalculator->buildPaymentSchedule(
            $order->storage,
            $order->startDate,
            $order->endDate,
            $frequency,
        );

        return $schedule->isEmpty() ? null : $schedule;
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

        /** @var OnboardingPaymentChoiceFormData $data */
        $data = $form->getData();
        \assert(null !== $data->paymentMethod);
        \assert(null !== $data->paymentFrequency);

        try {
            $this->commandBus->dispatch(new ChooseOnboardingPaymentCommand(
                order: $this->getOrder(),
                paymentMethod: $data->paymentMethod,
                paymentFrequency: $data->paymentFrequency,
            ));
        } catch (\Throwable $rawException) {
            $exception = HandlerFailureUnwrap::unwrap($rawException);

            if ($exception instanceof \DomainException) {
                $this->submitError = 'Zvolený způsob platby nelze pro tento pronájem použít. Zkuste to prosím znovu.';
            } else {
                $this->logger->error('Onboarding payment choice failed', ['exception' => $exception]);
                $this->submitError = 'Při ukládání volby došlo k chybě. Zkuste to prosím znovu.';
            }

            return null;
        }

        return new RedirectResponse($this->urlGenerator->generate('public_customer_signing', [
            'token' => $this->token,
        ]));
    }
}
