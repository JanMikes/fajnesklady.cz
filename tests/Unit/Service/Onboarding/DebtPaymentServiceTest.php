<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Onboarding;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\Onboarding\DebtPaymentService;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use App\Service\StorageAssignment;
use App\Service\StorageAvailabilityChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class DebtPaymentServiceTest extends TestCase
{
    public function testConfirmDebtPaidMarksDebtAsPaidForStandardBilling(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);
        $order->markAsAdminCreated();
        $order->setOnboardingBillingTerms(35000, null);

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects(self::never())->method('dispatch');

        $service = $this->buildService($commandBus);
        $service->confirmDebtPaid($order, new \DateTimeImmutable('2025-06-15 12:00:00'), 'gopay-123');

        self::assertNotNull($order->debtPaidAt);
        self::assertFalse($order->hasUnpaidDebt());
        self::assertTrue($order->hasDebt());
    }

    public function testConfirmDebtPaidAutoCompletesForFreeCustomer(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);
        $order->markAsAdminCreated();
        $order->setOnboardingBillingTerms(0, null);
        $order->acceptTerms(new \DateTimeImmutable('2025-06-15 12:00:00'));

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects(self::once())->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = $this->buildService($commandBus);
        $service->confirmDebtPaid($order, new \DateTimeImmutable('2025-06-15 12:00:00'));

        self::assertFalse($order->hasUnpaidDebt());
    }

    public function testConfirmDebtPaidAutoCompletesForExternallyPrepaidCustomer(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);
        $order->markAsAdminCreated();
        $order->setOnboardingBillingTerms(35000, new \DateTimeImmutable('2026-12-31'));
        $order->acceptTerms(new \DateTimeImmutable('2025-06-15 12:00:00'));

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects(self::once())->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = $this->buildService($commandBus);
        $service->confirmDebtPaid($order, new \DateTimeImmutable('2025-06-15 12:00:00'));

        self::assertFalse($order->hasUnpaidDebt());
    }

    public function testConfirmDebtPaidDoesNotAutoCompleteForStandardBilling(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(50000);
        $order->markAsAdminCreated();
        $order->setOnboardingBillingTerms(35000, null);
        $order->acceptTerms(new \DateTimeImmutable('2025-06-15 12:00:00'));

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects(self::never())->method('dispatch');

        $service = $this->buildService($commandBus);
        $service->confirmDebtPaid($order, new \DateTimeImmutable('2025-06-15 12:00:00'));

        self::assertFalse($order->hasUnpaidDebt());
        self::assertTrue($order->canBePaid());
    }

    private function buildService(MessageBusInterface $commandBus): DebtPaymentService
    {
        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn(Uuid::v7());

        $orderRepository = $this->createStub(OrderRepository::class);
        $contractRepository = $this->createStub(ContractRepository::class);

        $unavailabilityRepository = $this->createStub(StorageUnavailabilityRepository::class);
        $unavailabilityRepository->method('findOverlappingByStorage')->willReturn([]);

        $availabilityChecker = new StorageAvailabilityChecker(
            $unavailabilityRepository,
            $orderRepository,
            $contractRepository,
        );

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageAssignment = new StorageAssignment(
            $storageRepository,
            $contractRepository,
            $availabilityChecker,
        );

        $auditLogRepository = $this->createStub(AuditLogRepository::class);
        $security = $this->createStub(Security::class);
        $clock = $this->createStub(\Psr\Clock\ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2025-06-15 12:00:00'));

        $auditLogger = new AuditLogger(
            $auditLogRepository,
            $identityProvider,
            $security,
            new RequestStack(),
            $clock,
        );

        $orderService = new OrderService(
            $identityProvider,
            $orderRepository,
            $contractRepository,
            $storageAssignment,
            $availabilityChecker,
            $storageRepository,
            new PriceCalculator(),
            $auditLogger,
        );

        return new DebtPaymentService($orderService, $auditLogger, $commandBus);
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', new \DateTimeImmutable('2025-06-15 12:00:00'));
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable('2025-06-15 12:00:00'));

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
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
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
            owner: $owner,
        );

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-16'),
            endDate: new \DateTimeImmutable('2025-07-16'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('2025-06-22'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }
}
