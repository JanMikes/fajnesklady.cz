<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;

/**
 * Spec 088: the customer's payment choice for a deferred admin onboarding —
 * locks method + frequency (and, downstream, billing mode + price + VS) on the
 * order before the customer signs.
 */
final readonly class ChooseOnboardingPaymentCommand
{
    public function __construct(
        public Order $order,
        public PaymentMethod $paymentMethod,
        public PaymentFrequency $paymentFrequency,
    ) {
    }
}
