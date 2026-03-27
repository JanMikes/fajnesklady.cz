<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\AcceptOrderTermsCommand;
use App\Command\AcceptOrderTermsHandler;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Repository\AuditLogRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class AcceptOrderTermsHandlerTest extends TestCase
{
    private ClockInterface&Stub $clock;
    private AcceptOrderTermsHandler $handler;

    protected function setUp(): void
    {
        $this->clock = $this->createStub(ClockInterface::class);

        $auditLogRepository = $this->createStub(AuditLogRepository::class);
        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn(Uuid::v7());
        $security = $this->createStub(Security::class);
        $requestStack = new RequestStack();

        $auditLogger = new AuditLogger(
            $auditLogRepository,
            $identityProvider,
            $security,
            $requestStack,
            $this->clock,
        );

        $this->handler = new AcceptOrderTermsHandler(
            $this->clock,
            $auditLogger,
        );
    }

    public function testAcceptsTermsAndReservesOrder(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);
        $order = $this->createOrder();

        ($this->handler)(new AcceptOrderTermsCommand(order: $order));

        $this->assertTrue($order->hasAcceptedTerms());
        $this->assertSame($now, $order->termsAcceptedAt);
        $this->assertTrue($order->storage->isReserved());
    }

    public function testDoesNotAcceptEarlyStartWaiverByDefault(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);
        $order = $this->createOrder();

        ($this->handler)(new AcceptOrderTermsCommand(order: $order));

        $this->assertFalse($order->hasAcceptedEarlyStartWaiver());
        $this->assertNull($order->earlyStartWaiverAcceptedAt);
    }

    public function testAcceptsEarlyStartWaiverWhenFlagIsTrue(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);
        $order = $this->createOrder();

        ($this->handler)(new AcceptOrderTermsCommand(
            order: $order,
            earlyStartWaiverAccepted: true,
        ));

        $this->assertTrue($order->hasAcceptedEarlyStartWaiver());
        $this->assertSame($now, $order->earlyStartWaiverAcceptedAt);
    }

    public function testDoesNotAcceptEarlyStartWaiverWhenFlagIsFalse(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);
        $order = $this->createOrder();

        ($this->handler)(new AcceptOrderTermsCommand(
            order: $order,
            earlyStartWaiverAccepted: false,
        ));

        $this->assertFalse($order->hasAcceptedEarlyStartWaiver());
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
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
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('+1 day'),
            endDate: new \DateTimeImmutable('+30 days'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
    }
}
