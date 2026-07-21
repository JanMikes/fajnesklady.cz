<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\AuditLogger;
use App\Service\PriceCalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ChooseOnboardingPaymentHandler
{
    public function __construct(
        private PriceCalculator $priceCalculator,
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(ChooseOnboardingPaymentCommand $command): void
    {
        $order = $command->order;
        if (!$order->canEditPaymentChoice()) {
            throw new \DomainException('Order is not awaiting an editable customer payment choice.');
        }
        \assert(null !== $order->endDate);

        $method = $command->paymentMethod;
        $frequency = $command->paymentFrequency;

        // External settlement is an admin-only concept — the customer only ever
        // pays by card or bank transfer.
        if (PaymentMethod::EXTERNAL === $method) {
            throw new \DomainException('External settlement is admin-only; the customer chooses card or bank transfer.');
        }

        $rentalDays = (int) $order->startDate->diff($order->endDate)->days;

        // Enforce the same matrix the public order form enforces (belt to the
        // form-level braces): cards are monthly recurring only (≥ 31 days),
        // yearly + one-time are bank-only.
        if (PaymentMethod::GOPAY === $method && (
            $rentalDays < PriceCalculator::WEEKLY_THRESHOLD_DAYS
            || PaymentFrequency::YEARLY === $frequency
            || PaymentFrequency::ONE_TIME === $frequency
        )) {
            throw new \DomainException('Card payments are monthly recurring only (≥ 31 days).');
        }
        if (PaymentFrequency::YEARLY === $frequency && $rentalDays < PriceCalculator::YEARLY_THRESHOLD_DAYS) {
            throw new \DomainException('Yearly payment requires a rental of at least 12 months.');
        }

        $firstPaymentPrice = $this->priceCalculator->calculateFirstPaymentPrice(
            $order->storage,
            $order->startDate,
            $order->endDate,
            $frequency,
        );
        $billingMode = BillingMode::derive($method, $frequency, $rentalDays);

        $order->applyCustomerPaymentChoice($method, $frequency, $firstPaymentPrice, $billingMode);
        $this->auditLogger->logOnboardingPaymentChosen($order);
    }
}
