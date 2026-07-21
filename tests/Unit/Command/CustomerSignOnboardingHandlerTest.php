<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CompleteOrderCommand;
use App\Command\CustomerSignOnboardingCommand;
use App\Command\CustomerSignOnboardingHandler;
use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\SigningMethod;
use App\Event\OrderPaid;
use App\Repository\AuditLogRepository;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use App\Service\Payment\VariableSymbolGenerator;
use App\Service\PriceCalculator;
use App\Service\SignatureStorage;
use App\Service\StorageAssignment;
use App\Service\StorageAvailabilityChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class CustomerSignOnboardingHandlerTest extends TestCase
{
    private string $tempDir;
    private ClockInterface&Stub $clock;
    private SignatureStorage $signatureStorage;
    private OrderService $orderService;
    private AuditLogger $auditLogger;

    /** @var list<string> */
    private array $savedAuditEventTypes = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/onboarding_sign_test_'.uniqid();
        $this->clock = $this->createStub(ClockInterface::class);

        $this->signatureStorage = new SignatureStorage($this->tempDir);

        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturnCallback(fn () => Uuid::v7());

        $this->savedAuditEventTypes = [];
        $auditLogRepository = $this->createStub(AuditLogRepository::class);
        $auditLogRepository->method('save')->willReturnCallback(function (AuditLog $log): void {
            $this->savedAuditEventTypes[] = $log->eventType;
        });
        $security = $this->createStub(Security::class);
        $requestStack = new RequestStack();

        $auditLogger = new AuditLogger(
            $auditLogRepository,
            $identityProvider,
            $security,
            $requestStack,
            $this->clock,
        );
        $this->auditLogger = $auditLogger;

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
            $this->clock,
        );

        $priceCalculator = new PriceCalculator();

        $this->orderService = new OrderService(
            $identityProvider,
            $orderRepository,
            $contractRepository,
            $storageAssignment,
            $availabilityChecker,
            $storageRepository,
            $priceCalculator,
            $auditLogger,
            new VariableSymbolGenerator($this->createStub(EntityManagerInterface::class)),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testSignsOrderWithExternalPayment(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $order = $this->createOrder();
        $order->markAsAdminCreated();
        $order->setSigningToken('test-token-123');
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (CompleteOrderCommand $cmd) use ($order): bool {
                return $cmd->order === $order;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $handler = new CustomerSignOnboardingHandler(
            $this->signatureStorage,
            $this->orderService,
            $this->auditLogger,
            $this->clock,
            $commandBus,
        );

        $command = new CustomerSignOnboardingCommand(
            order: $order,
            signatureDataUrl: $this->createValidPngDataUrl(),
            signingMethod: SigningMethod::DRAW,
            signingPlace: 'Praha',
        );

        ($handler)($command);

        $this->assertTrue($order->hasSignature());
        $this->assertTrue($order->hasAcceptedTerms());
        $this->assertNull($order->signingToken);
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNotNull($order->paidAt);

        // No money moved through the platform: the OrderPaid event must carry
        // an explicit 0 so RecordPaymentOnOrderPaidHandler never records a
        // revenue-bearing Payment (phantom revenue / self-billing leak).
        $orderPaidEvents = array_values(array_filter(
            $order->popEvents(),
            static fn (object $event): bool => $event instanceof OrderPaid,
        ));
        $this->assertCount(1, $orderPaidEvents);
        $this->assertSame(0, $orderPaidEvents[0]->amountOverride);

        // And the audit trail must say "settled externally", never "Platba přijata".
        $this->assertContains('paid_externally', $this->savedAuditEventTypes);
        $this->assertNotContains('paid', $this->savedAuditEventTypes);
    }

    public function testSignsOrderWithGoPayPayment(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $order = $this->createOrder();
        $order->markAsAdminCreated();
        $order->setSigningToken('test-token-456');
        $order->setPaymentMethod(PaymentMethod::GOPAY);

        $commandBus = $this->createStub(MessageBusInterface::class);
        $handler = new CustomerSignOnboardingHandler(
            $this->signatureStorage,
            $this->orderService,
            $this->auditLogger,
            $this->clock,
            $commandBus,
        );

        $command = new CustomerSignOnboardingCommand(
            order: $order,
            signatureDataUrl: $this->createValidPngDataUrl(),
            signingMethod: SigningMethod::TYPED,
            signingPlace: 'Brno',
            typedName: 'Jan Novak',
            styleId: 'dancing-script',
        );

        ($handler)($command);

        $this->assertTrue($order->hasSignature());
        $this->assertSame(SigningMethod::TYPED, $order->signingMethod);
        $this->assertSame('Jan Novak', $order->signatureTypedName);
        $this->assertSame('dancing-script', $order->signatureStyleId);
        $this->assertTrue($order->hasAcceptedTerms());
        $this->assertTrue($order->storage->isReserved());
        $this->assertNull($order->signingToken);
        $this->assertSame(OrderStatus::RESERVED, $order->status);
    }

    public function testRejectsOrderWithoutSigningToken(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $order = $this->createOrder();
        $order->markAsAdminCreated();
        // No signing token set

        $commandBus = $this->createStub(MessageBusInterface::class);
        $handler = new CustomerSignOnboardingHandler(
            $this->signatureStorage,
            $this->orderService,
            $this->auditLogger,
            $this->clock,
            $commandBus,
        );

        $command = new CustomerSignOnboardingCommand(
            order: $order,
            signatureDataUrl: $this->createValidPngDataUrl(),
            signingMethod: SigningMethod::DRAW,
            signingPlace: 'Praha',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Order has no signing token.');

        ($handler)($command);
    }

    public function testRejectsNonAdminOrder(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $order = $this->createOrder();
        // Not marked as admin-created
        $order->setSigningToken('test-token-789');

        $commandBus = $this->createStub(MessageBusInterface::class);
        $handler = new CustomerSignOnboardingHandler(
            $this->signatureStorage,
            $this->orderService,
            $this->auditLogger,
            $this->clock,
            $commandBus,
        );

        $command = new CustomerSignOnboardingCommand(
            order: $order,
            signatureDataUrl: $this->createValidPngDataUrl(),
            signingMethod: SigningMethod::DRAW,
            signingPlace: 'Praha',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Order is not an admin-created onboarding order.');

        ($handler)($command);
    }

    public function testClearsSigningToken(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($now);

        $order = $this->createOrder();
        $order->markAsAdminCreated();
        $order->setSigningToken('token-to-clear');
        $order->setPaymentMethod(PaymentMethod::GOPAY);

        $this->assertSame('token-to-clear', $order->signingToken);

        $commandBus = $this->createStub(MessageBusInterface::class);
        $handler = new CustomerSignOnboardingHandler(
            $this->signatureStorage,
            $this->orderService,
            $this->auditLogger,
            $this->clock,
            $commandBus,
        );

        $command = new CustomerSignOnboardingCommand(
            order: $order,
            signatureDataUrl: $this->createValidPngDataUrl(),
            signingMethod: SigningMethod::DRAW,
            signingPlace: 'Praha',
        );

        ($handler)($command);

        $this->assertNull($order->signingToken);
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
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
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
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('+1 day'),
            endDate: new \DateTimeImmutable('+30 days'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createValidPngDataUrl(): string
    {
        $image = imagecreatetruecolor(1, 1);
        $white = imagecolorallocate($image, 255, 255, 255);
        \assert(false !== $white);
        imagefill($image, 0, 0, $white);

        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();
        \assert(false !== $pngData);

        return 'data:image/png;base64,'.base64_encode($pngData);
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
