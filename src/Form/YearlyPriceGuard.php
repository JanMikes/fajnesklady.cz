<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The yearly rate is the price of a WHOLE year paid upfront ("Sazba roční
 * předem (Kč za rok)"). In 2026-07 four storage types were saved with the
 * per-month figure in that field (1 350 instead of 16 200), and customers
 * bought a year for roughly one month's rent. A yearly rate below
 * {@see self::MIN_MONTHS} × the 6+ month monthly rate cannot be a real
 * yearly-prepayment discount, so the price-list forms reject it.
 */
final class YearlyPriceGuard
{
    public const int MIN_MONTHS = 6;

    public static function validate(
        ExecutionContextInterface $context,
        ?float $yearlyPrice,
        ?float $monthlyLongTermPrice,
        string $atPath,
    ): void {
        if (null === $yearlyPrice || null === $monthlyLongTermPrice || $monthlyLongTermPrice <= 0) {
            return;
        }

        if ($yearlyPrice >= self::MIN_MONTHS * $monthlyLongTermPrice) {
            return;
        }

        $context->buildViolation(sprintf(
            'Roční sazba je cena za celý rok předem. %s Kč za rok odpovídá jen %s Kč měsíčně, ale sazba na 6+ měs. je %s Kč za měsíc — zadejte cenu za celý rok (např. 12 × měsíční cena se slevou).',
            self::format($yearlyPrice),
            self::format($yearlyPrice / 12),
            self::format($monthlyLongTermPrice),
        ))
            ->atPath($atPath)
            ->addViolation();
    }

    private static function format(float $czk): string
    {
        return number_format($czk, 0, ',', ' ');
    }
}
