<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentFrequency: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';
    case ONE_TIME = 'one_time'; // whole rental paid upfront in a single bank transfer (spec 078)

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $frequency) => $frequency->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::MONTHLY => 'Měsíční platba',
            self::YEARLY => 'Roční platba (jednou ročně)',
            self::ONE_TIME => 'Jednorázová platba předem (celá částka)',
        };
    }

    /**
     * Label for an admin-set individual price, which is a per-month figure on
     * monthly orders, a per-year figure on yearly orders, and the whole-rental
     * total on single-payment upfront orders. Shared by the admin order list,
     * order-detail banner and portal badges.
     */
    public function individualPriceLabel(): string
    {
        return match ($this) {
            self::MONTHLY => 'Individuální měsíční cena',
            self::YEARLY => 'Individuální roční cena',
            self::ONE_TIME => 'Individuální celková cena (celý pronájem)',
        };
    }
}
