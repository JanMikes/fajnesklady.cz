<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Enum\RefundMode;
use App\Exception\RefundNotPossible;

/**
 * Pure refund arithmetic (spec 092).
 *
 * A paid period is [periodStart, periodEnd) — periodEnd is the next billing
 * date / paidThroughDate and is NOT part of the period. A prorated refund
 * returns every day from $refundFrom (inclusive, admin-chosen) to periodEnd:
 *
 *     amount × days(max(refundFrom, periodStart), periodEnd) / days(periodStart, periodEnd)
 *
 * rounded to whole koruny,
 * so a refundFrom on or before the period start returns the whole payment.
 * Days are calendar days compared on the date only (no time-of-day, no DST).
 */
final class RefundCalculator
{
    public static function quote(
        int $paymentAmount,
        RefundMode $mode,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
        ?\DateTimeImmutable $refundFrom,
    ): RefundQuote {
        if ($paymentAmount <= 0) {
            throw new \InvalidArgumentException('Only a positive payment can be refunded.');
        }

        $periodDays = null !== $periodStart && null !== $periodEnd
            ? self::days($periodStart, $periodEnd)
            : null;

        if (RefundMode::FULL === $mode) {
            return new RefundQuote($paymentAmount, $periodDays, $periodDays);
        }

        if (RefundMode::PRORATED !== $mode) {
            throw new \InvalidArgumentException(sprintf('Refund mode "%s" has no calculation.', $mode->value));
        }

        if (null === $periodStart || null === $periodEnd || null === $periodDays || $periodDays <= 0) {
            throw new RefundNotPossible('Období zaplacené platbou není známé — vrátit lze jen celou platbu.');
        }

        if (null === $refundFrom) {
            throw new RefundNotPossible('Zadejte datum, od kterého se má platba vrátit.');
        }

        $from = self::date($refundFrom) < self::date($periodStart) ? $periodStart : $refundFrom;
        $refundedDays = self::days($from, $periodEnd);

        if ($refundedDays <= 0) {
            throw new RefundNotPossible(sprintf('Zaplacené období končí %s — od zvoleného data už není co vracet.', self::date($periodEnd)->modify('-1 day')->format('d.m.Y')));
        }

        // Whole koruny: the app, the e-mails and the credit note all show
        // whole Kč, so a haléř remainder would make them disagree.
        $amount = (int) round($paymentAmount * $refundedDays / $periodDays / 100) * 100;

        if ($amount <= 0) {
            throw new RefundNotPossible('Poměrná částka k vrácení vychází na 0 Kč.');
        }

        return new RefundQuote(min($amount, $paymentAmount), $refundedDays, $periodDays);
    }

    /**
     * Calendar days from $from (inclusive) to $to (exclusive).
     */
    public static function days(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $diff = self::date($from)->diff(self::date($to));

        return $diff->invert ? -(int) $diff->days : (int) $diff->days;
    }

    private static function date(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
