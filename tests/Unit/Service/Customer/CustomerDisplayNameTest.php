<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Customer;

use App\Service\Customer\CustomerDisplayName;
use PHPUnit\Framework\TestCase;

final class CustomerDisplayNameTest extends TestCase
{
    public function testPersonalNameIsUsedWhenNoCompanyIsSet(): void
    {
        self::assertFalse(CustomerDisplayName::isCompany(null));
        self::assertSame('Jan Novak', CustomerDisplayName::resolve('Jan Novak', null));
    }

    public function testBlankCompanyNameIsNotACompany(): void
    {
        self::assertFalse(CustomerDisplayName::isCompany(''));
        self::assertFalse(CustomerDisplayName::isCompany('   '));
        self::assertSame('Jan Novak', CustomerDisplayName::resolve('Jan Novak', '  '));
    }

    public function testCompanyNameTakesPrecedenceOverPersonalName(): void
    {
        self::assertTrue(CustomerDisplayName::isCompany('Skladová Eva s.r.o.'));
        self::assertSame(
            'Skladová Eva s.r.o.',
            CustomerDisplayName::resolve('Eva Najemce', 'Skladová Eva s.r.o.'),
        );
    }

    public function testCompanyNameIsTrimmed(): void
    {
        self::assertSame('Firma s.r.o.', CustomerDisplayName::resolve('Eva Najemce', '  Firma s.r.o. '));
    }
}
