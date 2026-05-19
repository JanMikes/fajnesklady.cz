<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Order;

final readonly class SigningPriceViewModel
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public int $monthlyPriceInHaler,
        public bool $isRecurring,
        public ?\DateTimeImmutable $paidThroughDate,
    ) {
    }

    public static function fromOrder(Order $order): self
    {
        return new self(
            situation: CustomerBillingSituation::fromOrder($order),
            monthlyPriceInHaler: $order->firstPaymentPrice,
            isRecurring: $order->isRecurring(),
            paidThroughDate: $order->paidThroughDate,
        );
    }
}
