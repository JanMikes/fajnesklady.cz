<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;

final readonly class SetOrderPaymentPreferencesCommand
{
    public function __construct(
        public Order $order,
        public BillingMode $billingMode,
        public ?PaymentMethod $paymentMethod,
    ) {
    }
}
