<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SettleOnboardingDebtCommand;
use App\Command\SettleOnboardingDebtHandler;
use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Event\OnboardingDebtPaid;
use App\Repository\AuditLogRepository;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\Onboarding\DebtPaymentService;
use App\Service\OrderService;
use App\Service\Payment\VariableSymbolGenerator;
use App\Service\PriceCalculator;
use App\Service\StorageAssignment;
use App\Service\StorageAvailabilityChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class SettleOnboardingDebtHandlerTest extends TestCase
{
    private const string NOW = '2025-06-15 12:00:00';

    /** @var list<AuditLog> */
    private array $auditRows = [];

    public function testMarksDebtPaidAndLogsAdminActionBeforeConfirmation(): void
    {
        $order = $this->createOrder();
        $order->markAsAdminCreated();
        $order->setOnboardingBillingTerms(35000, null);
        $order->setOnboardingDebt(1_656_000);
        $order->popEvents();

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects(self::never())->method('dispatch'); // standard billing → no auto-complete

        ($this->buildHandler($commandBus))(new SettleOnboardingDebtCommand($order));

        self::assertEquals(new \DateTimeImmutable(self::NOW), $order->debtPaidAt);
        self::assertFalse($order->hasUnpaidDebt());
        self::assertTrue($order->canBePaid(), 'standard billing stays payable — first rent is still owed');

        // The domain event that issues the Fakturoid invoice + receipt e-mail is recorded.
        $events = $order->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OnboardingDebtPaid::class, $events[0]);
        self::assertSame(1_656_000, $events[0]->amountInHaler);

        self::assertSame(
            ['onboarding_debt_settled', 'debt_payment_confirmed'],
            array_map(static fn (AuditLog $row) => $row->eventType, $this->auditRows),
        );
        self::assertSame(
            ['amount' => 1_656_000, 'source' => 'admin_external'],
            $this->auditRows[0]->payload,
        );
    }

    public function testThrowsWhenOrderHasNoUnpaidDebt(): void
    {
        $order = $this->createOrder();
        $order->markAsAdminCreated();
        $order->setOnboardingDebt(50000);
        $order->markDebtPaid(new \DateTimeImmutable('2025-06-01 10:00:00'));
        $order->popEvents();

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects(self::never())->method('dispatch');
        $handler = $this->buildHandler($commandBus);

        $this->expectException(\DomainException::class);

        try {
            $handler(new SettleOnboardingDebtCommand($order));
        } finally {
            self::assertSame([], $this->auditRows, 'nothing may be audit-logged when the guard rejects');
            self::assertSame([], $order->popEvents());
        }
    }

    private function buildHandler(MessageBusInterface $commandBus): SettleOnboardingDebtHandler
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW));

        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn(Uuid::v7());

        $auditLogRepository = $this->createStub(AuditLogRepository::class);
        $auditLogRepository->method('save')->willReturnCallback(function (AuditLog $row): void {
            $this->auditRows[] = $row;
        });
        $auditLogger = new AuditLogger(
            $auditLogRepository,
            $identityProvider,
            $this->createStub(Security::class),
            new RequestStack(),
            $clock,
        );

        $orderRepository = $this->createStub(OrderRepository::class);
        $contractRepository = $this->createStub(ContractRepository::class);
        $storageRepository = $this->createStub(StorageRepository::class);
        $unavailabilityRepository = $this->createStub(StorageUnavailabilityRepository::class);
        $unavailabilityRepository->method('findOverlappingByStorage')->willReturn([]);
        $availabilityChecker = new StorageAvailabilityChecker($unavailabilityRepository, $orderRepository, $contractRepository);

        $orderService = new OrderService(
            $identityProvider,
            $orderRepository,
            $contractRepository,
            new StorageAssignment($storageRepository, $contractRepository, $availabilityChecker, $clock),
            $availabilityChecker,
            $storageRepository,
            new PriceCalculator(),
            $auditLogger,
            new VariableSymbolGenerator($this->createStub(EntityManagerInterface::class)),
        );

        return new SettleOnboardingDebtHandler(
            new DebtPaymentService($orderService, $auditLogger, $commandBus),
            $auditLogger,
            $clock,
        );
    }

    private function createOrder(): Order
    {
        $createdAt = new \DateTimeImmutable('2025-06-01 12:00:00');
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', $createdAt);

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $createdAt,
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
            createdAt: $createdAt,
        );
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $createdAt,
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2026-06-30'),
            firstPaymentPrice: 35000,
            expiresAt: $createdAt->modify('+30 days'),
            createdAt: $createdAt,
        );
        $order->reserve($createdAt);

        return $order;
    }
}
