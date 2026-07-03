<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\PriceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec 076 payment matrix — BillingMode::derive() is the single source of
 * truth: cards are recurring-monthly-only, bank transfer splits one-shot vs
 * manual on the weekly threshold, yearly and EXTERNAL are always manual.
 */
final class BillingModeDeriveTest extends TestCase
{
    /**
     * @return iterable<string, array{PaymentMethod, PaymentFrequency, int, BillingMode}>
     */
    public static function matrix(): iterable
    {
        yield 'card monthly short window still derives AUTO (validation rejects it separately)' => [
            PaymentMethod::GOPAY, PaymentFrequency::MONTHLY, 14, BillingMode::AUTO_RECURRING,
        ];
        yield 'card monthly at threshold' => [
            PaymentMethod::GOPAY, PaymentFrequency::MONTHLY, PriceCalculator::WEEKLY_THRESHOLD_DAYS, BillingMode::AUTO_RECURRING,
        ];
        yield 'card monthly long' => [
            PaymentMethod::GOPAY, PaymentFrequency::MONTHLY, 900, BillingMode::AUTO_RECURRING,
        ];
        yield 'bank transfer below threshold is one-time' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::MONTHLY, PriceCalculator::WEEKLY_THRESHOLD_DAYS - 1, BillingMode::ONE_TIME,
        ];
        yield 'bank transfer at threshold is manual' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::MONTHLY, PriceCalculator::WEEKLY_THRESHOLD_DAYS, BillingMode::MANUAL_RECURRING,
        ];
        yield 'bank transfer long is manual' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::MONTHLY, 400, BillingMode::MANUAL_RECURRING,
        ];
        yield 'yearly is always manual (bank)' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::YEARLY, 400, BillingMode::MANUAL_RECURRING,
        ];
        yield 'yearly is always manual even for card (validation rejects the combination separately)' => [
            PaymentMethod::GOPAY, PaymentFrequency::YEARLY, 400, BillingMode::MANUAL_RECURRING,
        ];
        yield 'yearly is always manual even below the weekly threshold' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::YEARLY, 14, BillingMode::MANUAL_RECURRING,
        ];
        yield 'external is manual' => [
            PaymentMethod::EXTERNAL, PaymentFrequency::MONTHLY, 90, BillingMode::MANUAL_RECURRING,
        ];
        yield 'external short is still manual (never one-time)' => [
            PaymentMethod::EXTERNAL, PaymentFrequency::MONTHLY, 10, BillingMode::MANUAL_RECURRING,
        ];
        yield 'upfront frequency short bank rental is one-time (spec 078)' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::ONE_TIME, 20, BillingMode::ONE_TIME,
        ];
        yield 'upfront frequency mid-length bank rental is one-time' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::ONE_TIME, 45, BillingMode::ONE_TIME,
        ];
        yield 'upfront frequency long bank rental is one-time' => [
            PaymentMethod::BANK_TRANSFER, PaymentFrequency::ONE_TIME, 400, BillingMode::ONE_TIME,
        ];
        yield 'upfront frequency derives one-time even for card (validation rejects the combination separately)' => [
            PaymentMethod::GOPAY, PaymentFrequency::ONE_TIME, 45, BillingMode::ONE_TIME,
        ];
        yield 'upfront frequency derives one-time even for external (validation rejects the combination separately)' => [
            PaymentMethod::EXTERNAL, PaymentFrequency::ONE_TIME, 45, BillingMode::ONE_TIME,
        ];
    }

    #[DataProvider('matrix')]
    public function testDerive(PaymentMethod $method, PaymentFrequency $frequency, int $days, BillingMode $expected): void
    {
        self::assertSame($expected, BillingMode::derive($method, $frequency, $days));
    }
}
