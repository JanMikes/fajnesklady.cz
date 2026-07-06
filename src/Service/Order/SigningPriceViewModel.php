<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Order;
use App\Enum\PaymentFrequency;

final readonly class SigningPriceViewModel
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public int $monthlyPriceInHaler,
        public bool $isRecurring,
        public ?\DateTimeImmutable $paidThroughDate,
        public ?\DateTimeImmutable $billingResumesOn,
        public bool $prepaidCoversWholeTerm,
        public int $recurringAmountInHaler,
        public string $cadenceLabel,
        public int $reminderDaysBefore,
    ) {
    }

    public static function fromOrder(Order $order): self
    {
        $situation = CustomerBillingSituation::fromOrder($order);

        return new self(
            situation: $situation,
            monthlyPriceInHaler: $order->firstPaymentPrice,
            isRecurring: $order->isRecurring(),
            paidThroughDate: $order->paidThroughDate,
            billingResumesOn: CustomerBillingSituation::EXTERNALLY_PREPAID === $situation
                ? $order->billingResumesOn()
                : null,
            prepaidCoversWholeTerm: $order->prepaidCoversWholeTerm(),
            recurringAmountInHaler: $order->firstPaymentPrice,
            cadenceLabel: PaymentFrequency::YEARLY === $order->paymentFrequency ? 'rok' : 'měsíc',
            reminderDaysBefore: abs($order->manualBillingOffsetInitial),
        );
    }
}
