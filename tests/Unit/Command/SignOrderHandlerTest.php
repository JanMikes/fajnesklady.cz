<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SignOrderCommand;
use App\Command\SignOrderHandler;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Enum\SigningMethod;
use App\Repository\AuditLogRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\SignatureStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class SignOrderHandlerTest extends TestCase
{
    private string $tempDir;
    private ClockInterface&MockObject $clock;
    private SignOrderHandler $handler;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/signature_handler_test_'.uniqid();
        $this->clock = $this->createMock(ClockInterface::class);

        $signatureStorage = new SignatureStorage($this->tempDir);

        $auditLogRepository = $this->createMock(AuditLogRepository::class);
        $identityProvider = $this->createMock(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn(Uuid::v7());
        $security = $this->createMock(Security::class);
        $requestStack = new RequestStack();

        $auditLogger = new AuditLogger(
            $auditLogRepository,
            $identityProvider,
            $security,
            $requestStack,
            $this->clock,
        );

        $this->handler = new SignOrderHandler(
            $signatureStorage,
            $auditLogger,
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testHandleDrawSignature(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable('2025-01-15 10:00:00');
        $dataUrl = $this->createValidPngDataUrl();

        $this->clock->method('now')->willReturn($now);

        $command = new SignOrderCommand(
            order: $order,
            signatureDataUrl: $dataUrl,
            signingMethod: SigningMethod::DRAW,
        );

        ($this->handler)($command);

        $this->assertTrue($order->hasSignature());
        $this->assertNotNull($order->signaturePath);
        $this->assertFileExists($order->signaturePath);
        $this->assertSame(SigningMethod::DRAW, $order->signingMethod);
        $this->assertNull($order->signatureTypedName);
        $this->assertNull($order->signatureStyleId);
        $this->assertSame($now, $order->signedAt);
    }

    public function testHandleTypedSignature(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable('2025-01-15 10:00:00');
        $dataUrl = $this->createValidPngDataUrl();

        $this->clock->method('now')->willReturn($now);

        $command = new SignOrderCommand(
            order: $order,
            signatureDataUrl: $dataUrl,
            signingMethod: SigningMethod::TYPED,
            typedName: 'Jan Novák',
            styleId: 'dancing-script',
        );

        ($this->handler)($command);

        $this->assertTrue($order->hasSignature());
        $this->assertSame(SigningMethod::TYPED, $order->signingMethod);
        $this->assertSame('Jan Novák', $order->signatureTypedName);
        $this->assertSame('dancing-script', $order->signatureStyleId);
    }

    public function testHandleCreatesSignatureFile(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable('2025-01-15 10:00:00');
        $dataUrl = $this->createValidPngDataUrl();

        $this->clock->method('now')->willReturn($now);

        $command = new SignOrderCommand(
            order: $order,
            signatureDataUrl: $dataUrl,
            signingMethod: SigningMethod::DRAW,
        );

        ($this->handler)($command);

        $this->assertDirectoryExists($this->tempDir);
        $expectedPath = $this->tempDir.'/signature_'.$order->id->toRfc4122().'.png';
        $this->assertFileExists($expectedPath);
        $this->assertSame($expectedPath, $order->signaturePath);
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

    private function createValidPngDataUrl(): string
    {
        $image = imagecreatetruecolor(1, 1);
        $white = imagecolorallocate($image, 255, 255, 255);
        \assert($white !== false);
        imagefill($image, 0, 0, $white);

        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();
        \assert($pngData !== false);

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
