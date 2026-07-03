<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\HandoverStatus;
use App\Enum\PaymentFrequency;
use App\Event\HandoverCompleted;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 083 — skipping the tenant side of a handover protocol.
 */
class HandoverProtocolTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testSkipOnPendingMarksWaitingOnLandlordWithoutCompleting(): void
    {
        $protocol = $this->createProtocol();
        $admin = $this->createUser('admin@example.com');

        $protocol->skipTenantSide($admin, $this->now);

        self::assertSame(HandoverStatus::TENANT_COMPLETED, $protocol->status);
        self::assertEquals($this->now, $protocol->tenantSkippedAt);
        self::assertSame($admin, $protocol->tenantSkippedBy);
        self::assertFalse($protocol->needsTenantCompletion());
        self::assertNull($protocol->completedAt);
        self::assertSame([], $protocol->popEvents(), 'Skip alone must not fire HandoverCompleted.');
    }

    public function testSkipWhenLandlordAlreadyCompletedCompletesProtocol(): void
    {
        $protocol = $this->createProtocol();
        $protocol->completeLandlordSide('Převzato.', '1234', $this->now->modify('-1 hour'));
        $admin = $this->createUser('admin@example.com');

        $protocol->skipTenantSide($admin, $this->now);

        self::assertSame(HandoverStatus::COMPLETED, $protocol->status);
        self::assertEquals($this->now, $protocol->completedAt);

        $events = $protocol->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(HandoverCompleted::class, $events[0]);
        self::assertSame('1234', $events[0]->newLockCode);
    }

    public function testLandlordCompletingAfterSkipCompletesProtocol(): void
    {
        $protocol = $this->createProtocol();
        $protocol->skipTenantSide($this->createUser('admin@example.com'), $this->now->modify('-1 hour'));

        $protocol->completeLandlordSide('Převzato.', '1234', $this->now);

        self::assertSame(HandoverStatus::COMPLETED, $protocol->status);
        self::assertEquals($this->now, $protocol->completedAt);

        $events = $protocol->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(HandoverCompleted::class, $events[0]);
    }

    public function testSkipAfterTenantCompletedThrows(): void
    {
        $protocol = $this->createProtocol();
        $protocol->completeTenantSide('Vyklizeno.', $this->now->modify('-1 hour'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Nájemce již předávací protokol vyplnil.');

        $protocol->skipTenantSide($this->createUser('admin@example.com'), $this->now);
    }

    public function testDoubleSkipThrows(): void
    {
        $protocol = $this->createProtocol();
        $protocol->skipTenantSide($this->createUser('admin@example.com'), $this->now->modify('-1 hour'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Strana nájemce již byla přeskočena.');

        $protocol->skipTenantSide($this->createUser('admin2@example.com'), $this->now);
    }

    public function testTenantCompletingAfterSkipThrows(): void
    {
        $protocol = $this->createProtocol();
        $protocol->skipTenantSide($this->createUser('admin@example.com'), $this->now->modify('-1 hour'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Strana nájemce byla přeskočena administrátorem.');

        $protocol->completeTenantSide('Vyklizeno.', $this->now);
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', $this->now);
    }

    private function createProtocol(): HandoverProtocol
    {
        $user = $this->createUser();
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $this->now,
        );
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $this->now,
        );
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->now,
            owner: null,
        );
        $startDate = $this->now->modify('-6 months');
        $endDate = $this->now->modify('+1 day');
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 35000,
            expiresAt: $this->now->modify('+7 days'),
            createdAt: $this->now,
        );
        $order->popEvents();
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $this->now,
        );

        return new HandoverProtocol(
            id: Uuid::v7(),
            contract: $contract,
            createdAt: $this->now,
        );
    }
}
