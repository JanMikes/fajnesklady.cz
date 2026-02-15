<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Repository\InvoiceRepository;
use App\Repository\UserRepository;
use App\Service\Fakturoid\FakturoidClient;
use App\Service\Identity\ProvideIdentity;
use App\Service\InvoicingService;
use App\Value\FakturoidInvoice;
use App\Value\FakturoidSubject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class InvoicingServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/invoices_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
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

    public function testIssueInvoiceForOrderCreatesSubjectWhenUserHasNone(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoiceId = Uuid::v7();

        $fakturoidClient = $this->createMock(FakturoidClient::class);
        $fakturoidClient->expects($this->once())
            ->method('createSubject')
            ->with($user)
            ->willReturn(new FakturoidSubject(12345, 'Test User'));

        $fakturoidClient->expects($this->once())
            ->method('createInvoice')
            ->with(12345, $order)
            ->willReturn(new FakturoidInvoice(99999, 'FV-2025-0001', 35000));

        $fakturoidClient->expects($this->once())
            ->method('downloadInvoicePdf')
            ->with(99999)
            ->willReturn('%PDF-1.4 mock content');

        $identityProvider = $this->createMock(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->expects($this->once())->method('save');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())->method('save')->with($user);

        $service = new InvoicingService(
            $fakturoidClient,
            $identityProvider,
            $invoiceRepository,
            $userRepository,
            new NullLogger(),
            $this->tempDir,
        );

        $invoice = $service->issueInvoiceForOrder($order, $now);

        $this->assertSame($invoiceId, $invoice->id);
        $this->assertSame($order, $invoice->order);
        $this->assertSame($user, $invoice->user);
        $this->assertSame(99999, $invoice->fakturoidInvoiceId);
        $this->assertSame('FV-2025-0001', $invoice->invoiceNumber);
        $this->assertSame(35000, $invoice->amount);
        $this->assertTrue($invoice->hasPdf());
        $this->assertSame(12345, $user->fakturoidSubjectId);
    }

    public function testIssueInvoiceForOrderReusesExistingSubject(): void
    {
        $user = $this->createUser();
        $user->setFakturoidSubjectId(54321, new \DateTimeImmutable());
        $order = $this->createOrder($user);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoiceId = Uuid::v7();

        $fakturoidClient = $this->createMock(FakturoidClient::class);
        $fakturoidClient->expects($this->never())->method('createSubject');

        $fakturoidClient->expects($this->once())
            ->method('createInvoice')
            ->with(54321, $order)
            ->willReturn(new FakturoidInvoice(88888, 'FV-2025-0002', 35000));

        $fakturoidClient->expects($this->once())
            ->method('downloadInvoicePdf')
            ->with(88888)
            ->willReturn('%PDF-1.4 mock content');

        $identityProvider = $this->createMock(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->expects($this->once())->method('save');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('save');

        $service = new InvoicingService(
            $fakturoidClient,
            $identityProvider,
            $invoiceRepository,
            $userRepository,
            new NullLogger(),
            $this->tempDir,
        );

        $invoice = $service->issueInvoiceForOrder($order, $now);

        $this->assertSame('FV-2025-0002', $invoice->invoiceNumber);
    }

    public function testIssueInvoiceForOrderStoresPdfLocally(): void
    {
        $user = $this->createUser();
        $user->setFakturoidSubjectId(11111, new \DateTimeImmutable());
        $order = $this->createOrder($user);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoiceId = Uuid::v7();
        $pdfContent = '%PDF-1.4 test pdf content';

        $fakturoidClient = $this->createMock(FakturoidClient::class);
        $fakturoidClient->method('createInvoice')
            ->willReturn(new FakturoidInvoice(77777, 'FV-2025-0003', 35000));
        $fakturoidClient->method('downloadInvoicePdf')
            ->willReturn($pdfContent);

        $identityProvider = $this->createMock(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $userRepository = $this->createMock(UserRepository::class);

        $service = new InvoicingService(
            $fakturoidClient,
            $identityProvider,
            $invoiceRepository,
            $userRepository,
            new NullLogger(),
            $this->tempDir,
        );

        $invoice = $service->issueInvoiceForOrder($order, $now);

        $this->assertTrue($invoice->hasPdf());
        $this->assertNotNull($invoice->pdfPath);
        $this->assertFileExists($invoice->pdfPath);
        $this->assertSame($pdfContent, file_get_contents($invoice->pdfPath));
    }

    public function testIssueInvoiceForOrderCreatesDirectoryIfNotExists(): void
    {
        $nestedDir = $this->tempDir.'/nested/invoices';
        $user = $this->createUser();
        $user->setFakturoidSubjectId(22222, new \DateTimeImmutable());
        $order = $this->createOrder($user);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoiceId = Uuid::v7();

        $fakturoidClient = $this->createMock(FakturoidClient::class);
        $fakturoidClient->method('createInvoice')
            ->willReturn(new FakturoidInvoice(66666, 'FV-2025-0004', 35000));
        $fakturoidClient->method('downloadInvoicePdf')
            ->willReturn('%PDF-1.4 content');

        $identityProvider = $this->createMock(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $userRepository = $this->createMock(UserRepository::class);

        $service = new InvoicingService(
            $fakturoidClient,
            $identityProvider,
            $invoiceRepository,
            $userRepository,
            new NullLogger(),
            $nestedDir,
        );

        $invoice = $service->issueInvoiceForOrder($order, $now);

        $this->assertDirectoryExists($nestedDir);
        $this->assertNotNull($invoice->pdfPath);
        $this->assertFileExists($invoice->pdfPath);
    }

    private function createUser(): User
    {
        return new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novak',
            new \DateTimeImmutable(),
        );
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovaci 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorageType(): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorage(): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $this->createStorageType(),
            place: $this->createPlace(),
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createOrder(User $user): Order
    {
        $storage = $this->createStorage();

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );
    }
}
