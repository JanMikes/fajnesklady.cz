<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;

/**
 * Admin confirms that the onboarding debt (Order.onboardingDebtInHaler — the
 * debt carried over from the customer's previous, pre-system contract) was
 * paid off-system: a different bank account, cash, … Neither the GoPay
 * webhook nor the FIO cron can ever see such a payment, so without this the
 * debt stays "Neuhrazen" forever.
 */
final readonly class SettleOnboardingDebtCommand
{
    public function __construct(
        public Order $order,
    ) {
    }
}
