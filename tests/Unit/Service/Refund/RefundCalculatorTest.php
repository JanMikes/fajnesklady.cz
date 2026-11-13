<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Refund;

use App\Enum\RefundMode;
use App\Exception\RefundNotPossible;
use App\Service\Refund\RefundCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundCalculatorTest extends TestCase
{
    public function testFullRefundReturnsWholePayment(): void
    {
        $quote = RefundCalculator::quote(175000, RefundMode::FULL, $this->d('2026-11-13'), $this->d('2026-12-10'), null);

        $this->assertSame(175000, $quote->amount);
        $this->assertSame(27, $quote->periodDays);
    }

    public function testFullRefundWorksWithoutKnownPeriod(): void
    {
        $quote = RefundCalculator::quote(35000, RefundMode::FULL, null, null, null);

        $this->assertSame(35000, $quote->amount);
        $this->assertNull($quote->periodDays);
    }

    /**
     * The 2026-11-13 unit-24 case: 1 750 Kč paid 13.11.–9.12. (27 days).
     */
    #[DataProvider('proratedCases')]
    public function testProrated(string $refundFrom, int $expectedAmount, int $expectedDays): void
    {
        $quote = RefundCalculator::quote(175000, RefundMode::PRORATED, $this->d('2026-11-13'), $this->d('2026-12-10'), $this->d($refundFrom));

        $this->assertSame($expectedAmount, $quote->amount);
        $this->assertSame($expectedDays, $quote->refundedDays);
        $this->assertSame(27, $quote->periodDays);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function proratedCases(): iterable
    {
        yield 'from the first day = everything' => ['2026-11-13', 175000, 27];
        yield 'before the period start = everything' => ['2026-11-01', 175000, 27];
        // 175000 × 20/27 = 129 629.6 haléře → 1 296 Kč (whole koruny)
        yield 'mid-period' => ['2026-11-20', 129600, 20];
        // only the last paid day (9.12.) — 175000/27 = 64.8 Kč → 65 Kč
        yield 'last paid day' => ['2026-12-09', 6500, 1];
    }

    public function testProratedIgnoresTimeOfDay(): void
    {
        $quote = RefundCalculator::quote(
            35000,
            RefundMode::PRORATED,
            new \DateTimeImmutable('2025-06-10 12:28:49'),
            new \DateTimeImmutable('2025-07-10 12:28:49'),
            new \DateTimeImmutable('2025-06-25 00:00:00'),
        );

        $this->assertSame(15, $quote->refundedDays);
        $this->assertSame(17500, $quote->amount);
    }

    public function testProratedAcrossDstChange(): void
    {
        $tz = new \DateTimeZone('Europe/Prague');
        $quote = RefundCalculator::quote(
            31000,
            RefundMode::PRORATED,
            new \DateTimeImmutable('2026-03-15', $tz),
            new \DateTimeImmutable('2026-04-15', $tz),
            new \DateTimeImmutable('2026-03-29', $tz),
        );

        $this->assertSame(31, $quote->periodDays);
        $this->assertSame(17, $quote->refundedDays);
        $this->assertSame(17000, $quote->amount);
    }

    public function testRefundFromPeriodEndIsRejected(): void
    {
        $this->expectException(RefundNotPossible::class);

        RefundCalculator::quote(175000, RefundMode::PRORATED, $this->d('2026-11-13'), $this->d('2026-12-10'), $this->d('2026-12-10'));
    }

    public function testProratedWithoutPeriodIsRejected(): void
    {
        $this->expectException(RefundNotPossible::class);

        RefundCalculator::quote(175000, RefundMode::PRORATED, null, null, $this->d('2026-11-20'));
    }

    public function testProratedWithoutDateIsRejected(): void
    {
        $this->expectException(RefundNotPossible::class);

        RefundCalculator::quote(175000, RefundMode::PRORATED, $this->d('2026-11-13'), $this->d('2026-12-10'), null);
    }

    public function testRoundingToZeroIsRejected(): void
    {
        $this->expectException(RefundNotPossible::class);

        // 1 day of 30 from 10 Kč = 0.33 Kč → 0 Kč
        RefundCalculator::quote(1000, RefundMode::PRORATED, $this->d('2025-06-10'), $this->d('2025-07-10'), $this->d('2025-07-09'));
    }

    public function testExternalModeHasNoCalculation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RefundCalculator::quote(1000, RefundMode::EXTERNAL, null, null, null);
    }

    private function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }
}
