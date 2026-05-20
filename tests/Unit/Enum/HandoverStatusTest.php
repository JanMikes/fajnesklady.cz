<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\HandoverStatus;
use PHPUnit\Framework\TestCase;

final class HandoverStatusTest extends TestCase
{
    public function testLabelsAreCzechWithDiacritics(): void
    {
        self::assertSame('Čeká na vyplnění', HandoverStatus::PENDING->label());
        self::assertSame('Čeká na pronajímatele', HandoverStatus::TENANT_COMPLETED->label());
        self::assertSame('Čeká na nájemce', HandoverStatus::LANDLORD_COMPLETED->label());
        self::assertSame('Vyplněno', HandoverStatus::COMPLETED->label());
    }

    public function testIsWaitingOnPendingMatchesBothSides(): void
    {
        self::assertTrue(HandoverStatus::PENDING->isWaitingOn('tenant'));
        self::assertTrue(HandoverStatus::PENDING->isWaitingOn('landlord'));
    }

    public function testIsWaitingOnTenantCompletedNeedsLandlord(): void
    {
        self::assertFalse(HandoverStatus::TENANT_COMPLETED->isWaitingOn('tenant'));
        self::assertTrue(HandoverStatus::TENANT_COMPLETED->isWaitingOn('landlord'));
    }

    public function testIsWaitingOnLandlordCompletedNeedsTenant(): void
    {
        self::assertTrue(HandoverStatus::LANDLORD_COMPLETED->isWaitingOn('tenant'));
        self::assertFalse(HandoverStatus::LANDLORD_COMPLETED->isWaitingOn('landlord'));
    }

    public function testIsWaitingOnCompletedIsFalseForEveryone(): void
    {
        self::assertFalse(HandoverStatus::COMPLETED->isWaitingOn('tenant'));
        self::assertFalse(HandoverStatus::COMPLETED->isWaitingOn('landlord'));
    }

    public function testIsWaitingOnUnknownActorIsFalse(): void
    {
        self::assertFalse(HandoverStatus::PENDING->isWaitingOn('admin'));
        self::assertFalse(HandoverStatus::TENANT_COMPLETED->isWaitingOn('admin'));
    }

    public function testBadgeClassPerStatus(): void
    {
        self::assertSame('badge-warning', HandoverStatus::PENDING->badgeClass());
        self::assertSame('badge-info', HandoverStatus::TENANT_COMPLETED->badgeClass());
        self::assertSame('badge-info', HandoverStatus::LANDLORD_COMPLETED->badgeClass());
        self::assertSame('badge-success', HandoverStatus::COMPLETED->badgeClass());
    }
}
