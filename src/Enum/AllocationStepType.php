<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The obligations incoming money can be applied to, in waterfall order
 * (spec 091). BILLING_CYCLE and FIRST_PAYMENT are mutually exclusive: a
 * contract on the manual track bills cycles, an order without one is still
 * awaiting its first payment.
 */
enum AllocationStepType: string
{
    case ONBOARDING_DEBT = 'onboarding_debt';
    case CONTRACT_DEBT = 'contract_debt';
    case BILLING_CYCLE = 'billing_cycle';
    case FIRST_PAYMENT = 'first_payment';
    case CREDIT = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::ONBOARDING_DEBT => 'Dluh z předchozí smlouvy',
            self::CONTRACT_DEBT => 'Dluh po ukončení smlouvy',
            self::BILLING_CYCLE => 'Nájem za období',
            self::FIRST_PAYMENT => 'První platba',
            self::CREDIT => 'Přeplatek převedený na další období',
        };
    }
}
