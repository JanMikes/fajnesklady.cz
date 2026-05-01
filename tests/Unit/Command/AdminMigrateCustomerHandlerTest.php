<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\AdminMigrateCustomerCommand;
use App\Command\AdminMigrateCustomerHandler;
use App\Entity\Contract;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use App\Repository\AuditLogRepository;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use App\Service\StorageAssignment;
use App\Service\StorageAvailabilityChecker;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class AdminMigrateCustomerHandlerTest extends TestCase
{
    private ClockInterface&Stub $clock;
    private UserRepository&Stub $userRepository;
    private ProvideIdentity&Stub $identityProvider;
    private string $contractsDirectory;
    private string $sourceFile;
    private AdminMigrateCustomerHandler $handler;

    protected function setUp(): void
    {
        $this->clock = $this->createStub(ClockInterface::class);
        $this->userRepository = $this->createStub(UserRepository::class);
        $this->identityProvider = $this->createStub(ProvideIdentity::class);
        $this->identityProvider->method('next')->willReturnCallback(fn () => Uuid::v7());
        $this->contractsDirectory = sys_get_temp_dir().'/contracts_test_'.uniqid();

        // Create a temporary source file to simulate uploaded contract
        $this->sourceFile = sys_get_temp_dir().'/source_contract_'.uniqid().'.pdf';
        file_put_contents($this->sourceFile, 'fake contract content');

        $orderService = $this->createOrderService();

        $this->handler = new AdminMigrateCustomerHandler(
            $this->userRepository,
            $orderService,
            $this->clock,
            $this->identityProvider,
            $this->contractsDirectory,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->contractsDirectory);
        if (file_exists($this->sourceFile)) {
            unlink($this->sourceFile);
        }
    }

    public function testMigratesNewCustomer(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place);
        $paidAt = new \DateTimeImmutable('2025-06-14 10:00:00');

        // User does not exist
        $this->userRepository->method('findByEmail')->willReturn(null);

        $command = new AdminMigrateCustomerCommand(
            email: 'new@example.com',
            firstName: 'Jan',
            lastName: 'Novak',
            phone: '+420123456789',
            birthDate: new \DateTimeImmutable('1990-01-15'),
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: null,
            billingCity: null,
            billingPostalCode: null,
            storage: $storage,
            storageType: $storageType,
            place: $place,
            rentalType: RentalType::UNLIMITED,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: null,
            contractDocumentPath: $this->sourceFile,
            totalPrice: 50000,
            paidAt: $paidAt,
        );

        $result = ($this->handler)($command);

        $this->assertInstanceOf(Contract::class, $result);
        $this->assertSame(OrderStatus::COMPLETED, $result->order->status);
        $this->assertTrue($result->order->isAdminCreated);
        $this->assertSame(PaymentMethod::EXTERNAL, $result->order->paymentMethod);
        $this->assertSame(50000, $result->order->totalPrice);
        $this->assertTrue($result->order->hasAcceptedTerms());
        $this->assertNotNull($result->documentPath);
        $this->assertTrue($result->isSigned());
    }

    public function testMigratesExistingUser(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $existingUser = new User(Uuid::v7(), 'existing@example.com', 'password', 'Existing', 'User', new \DateTimeImmutable());
        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place);

        // User already exists
        $this->userRepository->method('findByEmail')->willReturn($existingUser);

        $command = new AdminMigrateCustomerCommand(
            email: 'existing@example.com',
            firstName: 'Existing',
            lastName: 'User',
            phone: null,
            birthDate: null,
            companyName: 'Test s.r.o.',
            companyId: '12345678',
            companyVatId: 'CZ12345678',
            billingStreet: 'Karlova 1',
            billingCity: 'Praha',
            billingPostalCode: '110 00',
            storage: $storage,
            storageType: $storageType,
            place: $place,
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: new \DateTimeImmutable('2025-12-31'),
            contractDocumentPath: $this->sourceFile,
            totalPrice: 35000,
            paidAt: new \DateTimeImmutable('2025-06-10'),
        );

        $result = ($this->handler)($command);

        $this->assertInstanceOf(Contract::class, $result);
        $this->assertSame($existingUser, $result->user);
        $this->assertSame(OrderStatus::COMPLETED, $result->order->status);
        // Billing info should be updated on existing user
        $this->assertSame('Test s.r.o.', $existingUser->companyName);
        $this->assertSame('12345678', $existingUser->companyId);
    }

    public function testOverridesPrice(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place);

        $this->userRepository->method('findByEmail')->willReturn(null);

        $adminPrice = 99900;

        $command = new AdminMigrateCustomerCommand(
            email: 'price@example.com',
            firstName: 'Price',
            lastName: 'Test',
            phone: null,
            birthDate: null,
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: null,
            billingCity: null,
            billingPostalCode: null,
            storage: $storage,
            storageType: $storageType,
            place: $place,
            rentalType: RentalType::UNLIMITED,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: null,
            contractDocumentPath: $this->sourceFile,
            totalPrice: $adminPrice,
            paidAt: new \DateTimeImmutable('2025-06-10'),
        );

        $result = ($this->handler)($command);

        $this->assertSame($adminPrice, $result->order->totalPrice);
    }

    public function testMovesContractDocument(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place);

        $this->userRepository->method('findByEmail')->willReturn(null);

        $command = new AdminMigrateCustomerCommand(
            email: 'doc@example.com',
            firstName: 'Doc',
            lastName: 'Test',
            phone: null,
            birthDate: null,
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: null,
            billingCity: null,
            billingPostalCode: null,
            storage: $storage,
            storageType: $storageType,
            place: $place,
            rentalType: RentalType::UNLIMITED,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: null,
            contractDocumentPath: $this->sourceFile,
            totalPrice: 50000,
            paidAt: new \DateTimeImmutable('2025-06-10'),
        );

        $result = ($this->handler)($command);

        // Source file should have been moved
        $this->assertFileDoesNotExist($this->sourceFile);
        // Contract document should be in the contracts directory
        $this->assertNotNull($result->documentPath);
        $this->assertFileExists($result->documentPath);
        $this->assertStringContainsString($this->contractsDirectory, $result->documentPath);
        $this->assertStringEndsWith('.pdf', $result->documentPath);
    }

    private function createOrderService(): OrderService
    {
        $orderRepository = $this->createStub(OrderRepository::class);
        $contractRepository = $this->createStub(ContractRepository::class);

        $auditLogRepository = $this->createStub(AuditLogRepository::class);
        $security = $this->createStub(Security::class);
        $requestStack = new RequestStack();

        $auditLogger = new AuditLogger(
            $auditLogRepository,
            $this->identityProvider,
            $security,
            $requestStack,
            $this->clock,
        );

        $storageRepository = $this->createStub(StorageRepository::class);
        $unavailabilityRepository = $this->createStub(StorageUnavailabilityRepository::class);
        $unavailabilityRepository->method('findOverlappingByStorage')->willReturn([]);

        $availabilityChecker = new StorageAvailabilityChecker(
            $unavailabilityRepository,
            $orderRepository,
            $contractRepository,
        );

        $storageAssignment = new StorageAssignment(
            $storageRepository,
            $contractRepository,
            $availabilityChecker,
        );

        $priceCalculator = new PriceCalculator();

        return new OrderService(
            $this->identityProvider,
            $orderRepository,
            $contractRepository,
            $storageAssignment,
            $priceCalculator,
            $auditLogger,
        );
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorageType(Place $place): StorageType
    {
        return new StorageType(
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
    }

    private function createStorage(StorageType $storageType, Place $place): Storage
    {
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());

        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
