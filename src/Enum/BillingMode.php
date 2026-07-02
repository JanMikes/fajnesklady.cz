<?php

declare(strict_types=1);

namespace App\Enum;

use App\Service\PriceCalculator;

enum BillingMode: string
{
    case ONE_TIME = 'one_time';
    case AUTO_RECURRING = 'auto_recurring';
    case MANUAL_RECURRING = 'manual_recurring';

    /**
     * Single source of truth for the payment matrix (spec 076). Billing mode
     * is never a user choice — it follows from the payment method, frequency
     * and rental length. Cards may ONLY establish recurring monthly payments;
     * one-shot and yearly payments are bank-transfer territory (EXTERNAL is
     * the admin-onboarding "handled outside the system" method).
     */
    public static function derive(PaymentMethod $paymentMethod, PaymentFrequency $frequency, int $rentalDays): self
    {
        if (PaymentFrequency::YEARLY === $frequency) {
            return self::MANUAL_RECURRING;
        }

        return match ($paymentMethod) {
            PaymentMethod::GOPAY => self::AUTO_RECURRING,
            PaymentMethod::BANK_TRANSFER => $rentalDays < PriceCalculator::WEEKLY_THRESHOLD_DAYS
                ? self::ONE_TIME
                : self::MANUAL_RECURRING,
            PaymentMethod::EXTERNAL => self::MANUAL_RECURRING,
        };
    }

    public function isRecurring(): bool
    {
        return self::ONE_TIME !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::ONE_TIME => 'Jednorázová platba',
            self::AUTO_RECURRING => 'Automatická platba kartou',
            self::MANUAL_RECURRING => 'Ručně schvalovaná platba (výzva e-mailem)',
        };
    }
}
