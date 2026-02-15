<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Enum\UserRole;
use App\Event\OrderCreated;
use App\Event\SendStorageAvailabilityWarningHandler;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\AtRiskContractChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendStorageAvailabilityWarningHandlerTest extends TestCase
{
    private MockObject $orderRepository;
    private MockObject $userRepository;
    private MockObject $atRiskContractChecker;
    private MockObject $mailer;
    private MockObject $urlGenerator;
    private MockObject $clock;
    private SendStorageAvailabilityWarningHandler $handler;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->atRiskContractChecker = $this->createMock(AtRiskContractChecker::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);

        $this->handler = new SendStorageAvailabilityWarningHandler(
            $this->orderRepository,
            $this->userRepository,
            $this->atRiskContractChecker,
            $this->mailer,
            $this->urlGenerator,
            $this->clock,
        );

        $this->urlGenerator->method('generate')->willReturn('https://example.com/portal');
    }

    public function testNoAtRiskContractsNoEmailsSent(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $event = new OrderCreated(
            $order->id,
            $order->user->id,
            $order->storage->id,
            $order->totalPrice,
            new \DateTimeImmutable(),
        );

        $this->orderRepository
            ->expects($this->once())
            ->method('get')
            ->with($order->id)
            ->willReturn($order);

        $this->clock
            ->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->atRiskContractChecker
            ->expects($this->once())
            ->method('findAtRiskContracts')
            ->with($order->storage->storageType, $now)
            ->willReturn([]);

        $this->mailer
            ->expects($this->never())
            ->method('send');

        ($this->handler)($event);
    }

    public function testOneAtRiskContractSendsUserAndAdminEmails(): void
    {
        $order = $this->createOrder();
        $contract = $this->createContract($order->storage->storageType);
        $admin = $this->createAdmin();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $event = new OrderCreated(
            $order->id,
            $order->user->id,
            $order->storage->id,
            $order->totalPrice,
            new \DateTimeImmutable(),
        );

        $this->orderRepository
            ->method('get')
            ->with($order->id)
            ->willReturn($order);

        $this->clock
            ->method('now')
            ->willReturn($now);

        $this->atRiskContractChecker
            ->method('findAtRiskContracts')
            ->willReturn([$contract]);

        $this->userRepository
            ->method('findByRole')
            ->with(UserRole::ADMIN)
            ->willReturn([$admin]);

        $sentEmails = [];
        $this->mailer
            ->expects($this->exactly(2)) // 1 user + 1 admin
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$sentEmails) {
                $sentEmails[] = $email;
            });

        ($this->handler)($event);

        $this->assertCount(2, $sentEmails);

        // First email should be to the tenant
        $tenantEmail = $sentEmails[0];
        $this->assertSame('tenant@example.com', $tenantEmail->getTo()[0]->getAddress());
        $this->assertNotNull($tenantEmail->getSubject());
        $this->assertStringContainsString('Váš typ skladu je žádaný', $tenantEmail->getSubject());

        // Second email should be to the admin
        $adminEmail = $sentEmails[1];
        $this->assertSame('admin@example.com', $adminEmail->getTo()[0]->getAddress());
        $this->assertNotNull($adminEmail->getSubject());
        $this->assertStringContainsString('1 uživatelů upozorněno', $adminEmail->getSubject());
    }

    public function testMultipleAtRiskContractsSendsMultipleUserEmails(): void
    {
        $order = $this->createOrder();
        $contract1 = $this->createContract($order->storage->storageType, 'tenant1@example.com');
        $contract2 = $this->createContract($order->storage->storageType, 'tenant2@example.com');
        $admin = $this->createAdmin();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $event = new OrderCreated(
            $order->id,
            $order->user->id,
            $order->storage->id,
            $order->totalPrice,
            new \DateTimeImmutable(),
        );

        $this->orderRepository
            ->method('get')
            ->willReturn($order);

        $this->clock
            ->method('now')
            ->willReturn($now);

        $this->atRiskContractChecker
            ->method('findAtRiskContracts')
            ->willReturn([$contract1, $contract2]);

        $this->userRepository
            ->method('findByRole')
            ->willReturn([$admin]);

        $sentEmails = [];
        $this->mailer
            ->expects($this->exactly(3)) // 2 users + 1 admin
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$sentEmails) {
                $sentEmails[] = $email;
            });

        ($this->handler)($event);

        $this->assertCount(3, $sentEmails);
        $this->assertSame('tenant1@example.com', $sentEmails[0]->getTo()[0]->getAddress());
        $this->assertSame('tenant2@example.com', $sentEmails[1]->getTo()[0]->getAddress());
        $this->assertNotNull($sentEmails[2]->getSubject());
        $this->assertStringContainsString('2 uživatelů upozorněno', $sentEmails[2]->getSubject());
    }

    public function testNoAdminsNoAdminEmailSent(): void
    {
        $order = $this->createOrder();
        $contract = $this->createContract($order->storage->storageType);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $event = new OrderCreated(
            $order->id,
            $order->user->id,
            $order->storage->id,
            $order->totalPrice,
            new \DateTimeImmutable(),
        );

        $this->orderRepository
            ->method('get')
            ->willReturn($order);

        $this->clock
            ->method('now')
            ->willReturn($now);

        $this->atRiskContractChecker
            ->method('findAtRiskContracts')
            ->willReturn([$contract]);

        $this->userRepository
            ->method('findByRole')
            ->with(UserRole::ADMIN)
            ->willReturn([]); // No admins

        $sentEmails = [];
        $this->mailer
            ->expects($this->once()) // Only user email, no admin
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$sentEmails) {
                $sentEmails[] = $email;
            });

        ($this->handler)($event);

        $this->assertCount(1, $sentEmails);
        $this->assertSame('tenant@example.com', $sentEmails[0]->getTo()[0]->getAddress());
    }

    private function createOrder(): Order
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovaci 123',
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
            innerHeight: 200,
            innerLength: 150,
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
        );

        $newTenant = new User(
            Uuid::v7(),
            'new-tenant@example.com',
            'password',
            'New',
            'Tenant',
            new \DateTimeImmutable(),
        );

        return new Order(
            id: Uuid::v7(),
            user: $newTenant,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('2025-08-01'),
            endDate: new \DateTimeImmutable('2025-09-01'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );
    }

    private function createContract(StorageType $storageType, string $tenantEmail = 'tenant@example.com'): Contract
    {
        $tenant = new User(
            Uuid::v7(),
            $tenantEmail,
            'password',
            'Test',
            'Tenant',
            new \DateTimeImmutable(),
        );

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovaci 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'B1',
            coordinates: ['x' => 100, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: new \DateTimeImmutable('2025-07-31'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $tenant,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: new \DateTimeImmutable('2025-07-31'),
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createAdmin(): User
    {
        return new User(
            Uuid::v7(),
            'admin@example.com',
            'password',
            'Admin',
            'User',
            new \DateTimeImmutable(),
        );
    }
}
