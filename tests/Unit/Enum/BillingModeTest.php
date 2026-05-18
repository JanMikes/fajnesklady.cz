<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\BillingMode;
use PHPUnit\Framework\TestCase;

final class BillingModeTest extends TestCase
{
    public function testIsRecurringIsTrueForAutoAndManualButFalseForOneTime(): void
    {
        self::assertTrue(BillingMode::AUTO_RECURRING->isRecurring());
        self::assertTrue(BillingMode::MANUAL_RECURRING->isRecurring());
        self::assertFalse(BillingMode::ONE_TIME->isRecurring());
    }

    public function testLabelsAreCzechWithDiacritics(): void
    {
        self::assertSame('Jednorázová platba', BillingMode::ONE_TIME->label());
        self::assertSame('Automatická platba kartou', BillingMode::AUTO_RECURRING->label());
        self::assertSame('Ručně schvalovaná platba (výzva e-mailem)', BillingMode::MANUAL_RECURRING->label());
    }

    public function testStringValuesPersist(): void
    {
        // Pins enum string values to their DB representations so a rename
        // (e.g., auto_recurring → automatic) is caught before it ships.
        $values = array_map(static fn (BillingMode $m): string => $m->value, BillingMode::cases());
        sort($values);

        self::assertSame(['auto_recurring', 'manual_recurring', 'one_time'], $values);
    }
}
