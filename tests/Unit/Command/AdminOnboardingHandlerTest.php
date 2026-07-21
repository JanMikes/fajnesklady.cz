<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\AdminOnboardingCommand;
use App\Command\AdminOnboardingHandler;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Repository\AuditLogRepository;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
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
use Symfony\Component\Uid\Uuid;

final class AdminOnboardingHandlerTest extends TestCase
{
    private Place $place;
    private StorageType $storageType;
    private Storage $storage;

    protected function setUp(): void
    {
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable('2025-06-01'));

        $this->place = new Place(Uuid::v7(), 'Test Place', 'Test Address', 'Praha', '110 00', null, new \DateTimeImmutable('2025-06-01'));

        $this->storageType = new StorageType(
            id: Uuid::v7(),
            place: $this->place,
            name: 'Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 150_000,
            defaultPricePerMonthLongTerm: 150_000,
            defaultPricePerYear: 150_000 * 12,
            createdAt: new \DateTimeImmutable('2025-06-01'),
        );

        $this->storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $this->storageType,
            place: $this->place,
            createdAt: new \DateTimeImmutable('2025-06-01'),
            owner: $owner,
        );
    }

    public function testPrepaidWithGoPayMethodForcesExternalAndManualBilling(): void
    {
        $order = $this->invokeHandler($this->createCommand(
            paymentMethod: PaymentMethod::GOPAY,
            paidThroughDate: new \DateTimeImmutable('2025-06-20'),
        ));

        self::assertSame(PaymentMethod::EXTERNAL, $order->paymentMethod);
        self::assertSame(BillingMode::MANUAL_RECURRING, $order->billingMode);
    }

    public function testFreeWithGoPayMethodForcesExternalAndManualBilling(): void
    {
        $order = $this->invokeHandler($this->createCommand(
            paymentMethod: PaymentMethod::GOPAY,
            individualMonthlyAmount: 0,
        ));

        self::assertSame(PaymentMethod::EXTERNAL, $order->paymentMethod);
        self::assertSame(BillingMode::MANUAL_RECURRING, $order->billingMode);
    }

    public function testGoPayWithoutPrepaidStaysAutoRecurring(): void
    {
        $order = $this->invokeHandler($this->createCommand(
            paymentMethod: PaymentMethod::GOPAY,
        ));

        self::assertSame(PaymentMethod::GOPAY, $order->paymentMethod);
        self::assertSame(BillingMode::AUTO_RECURRING, $order->billingMode);
    }

    public function testPrepaidWithDebtKeepsMethodForDebtButBillsRentalManually(): void
    {
        $order = $this->invokeHandler($this->createCommand(
            paymentMethod: PaymentMethod::GOPAY,
            paidThroughDate: new \DateTimeImmutable('2025-06-20'),
            debtInHaler: 50_000,
        ));

        self::assertSame(PaymentMethod::GOPAY, $order->paymentMethod);
        self::assertSame(BillingMode::MANUAL_RECURRING, $order->billingMode);
    }

    public function testLetCustomerChooseCreatesDeferredOrder(): void
    {
        $order = $this->invokeHandler($this->createCommand(
            paymentMethod: null,
            letCustomerChoosePayment: true,
        ));

        self::assertTrue($order->customerChoosesPayment);
        self::assertTrue($order->isAwaitingPaymentChoice());
        self::assertNull($order->paymentMethod);
        // Spec 089: a VS is assigned at creation even for a deferred-choice order.
        self::assertNotNull($order->variableSymbol);
        self::assertNull($order->individualMonthlyAmount);
        // Provisional MONTHLY ceník price until the customer chooses at signing.
        self::assertSame(PaymentFrequency::MONTHLY, $order->paymentFrequency);
        self::assertGreaterThan(0, $order->firstPaymentPrice);
    }

    private function createCommand(
        ?PaymentMethod $paymentMethod = null,
        ?\DateTimeImmutable $paidThroughDate = null,
        ?int $individualMonthlyAmount = null,
        ?int $debtInHaler = null,
        bool $letCustomerChoosePayment = false,
    ): AdminOnboardingCommand {
        return new AdminOnboardingCommand(
            email: 'customer@example.com',
            firstName: 'Jan',
            lastName: 'Novák',
            phone: null,
            birthDate: null,
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: 'Hlavní 1',
            billingCity: 'Praha',
            billingPostalCode: '110 00',
            storage: $this->storage,
            storageType: $this->storageType,
            place: $this->place,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: new \DateTimeImmutable('2026-06-15'),
            paymentMethod: $paymentMethod,
            individualMonthlyAmount: $individualMonthlyAmount,
            paidThroughDate: $paidThroughDate,
            createdByAdminId: Uuid::v7(),
            paymentFrequency: $letCustomerChoosePayment ? null : PaymentFrequency::MONTHLY,
            debtInHaler: $debtInHaler,
            letCustomerChoosePayment: $letCustomerChoosePayment,
        );
    }

    private function invokeHandler(AdminOnboardingCommand $command): Order
    {
        $customer = new User(Uuid::v7(), 'customer@example.com', 'password', 'Jan', 'Novák', new \DateTimeImmutable('2025-06-15'));
        $admin = new User(Uuid::v7(), 'admin@example.com', 'password', 'Admin', 'Admin', new \DateTimeImmutable('2025-06-15'));

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2025-06-15 12:00:00'));

        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturnCallback(fn () => Uuid::v7());

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn($customer);
        $userRepository->method('get')->willReturn($admin);

        $auditLogger = new AuditLogger(
            $this->createStub(AuditLogRepository::class),
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

        $availabilityChecker = new StorageAvailabilityChecker(
            $unavailabilityRepository,
            $orderRepository,
            $contractRepository,
        );

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

        $handler = new AdminOnboardingHandler(
            $userRepository,
            $orderService,
            $clock,
            $identityProvider,
            sys_get_temp_dir(),
        );

        return ($handler)($command);
    }
}
