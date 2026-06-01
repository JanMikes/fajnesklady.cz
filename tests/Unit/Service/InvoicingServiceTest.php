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
use App\Service\Fakturoid\StaleFakturoidSubjectException;
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

        $identityProvider = $this->createStub(ProvideIdentity::class);
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

        $identityProvider = $this->createStub(ProvideIdentity::class);
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

    public function testIssueInvoiceForDebtIssuesPaidInvoiceFromDebtAmount(): void
    {
        $user = $this->createUser();
        $user->setFakturoidSubjectId(54321, new \DateTimeImmutable());
        $order = $this->createOrder($user);
        $order->setOnboardingDebt(120_000); // 1 200 Kč gross
        $paidAt = new \DateTimeImmutable('2025-06-15 10:00:00');
        $order->markDebtPaid($paidAt);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoiceId = Uuid::v7();

        $fakturoidClient = $this->createMock(FakturoidClient::class);
        $fakturoidClient->expects($this->never())->method('createInvoice');
        $fakturoidClient->expects($this->once())
            ->method('createDebtInvoice')
            ->with(54321, $order)
            ->willReturn(new FakturoidInvoice(70001, 'FV-2025-DEBT', 120_000));
        // The debt is already paid, so the invoice is marked paid at debtPaidAt.
        $fakturoidClient->expects($this->once())
            ->method('markInvoiceAsPaid')
            ->with(70001, $paidAt);
        $fakturoidClient->expects($this->once())
            ->method('downloadInvoicePdf')
            ->with(70001)
            ->willReturn('%PDF-1.4 debt invoice');

        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->expects($this->once())->method('save');

        $userRepository = $this->createStub(UserRepository::class);

        $service = new InvoicingService(
            $fakturoidClient,
            $identityProvider,
            $invoiceRepository,
            $userRepository,
            new NullLogger(),
            $this->tempDir,
        );

        $invoice = $service->issueInvoiceForDebt($order, $now);

        $this->assertSame($invoiceId, $invoice->id);
        $this->assertSame($order, $invoice->order);
        $this->assertSame(120_000, $invoice->amount);
        $this->assertSame('FV-2025-DEBT', $invoice->invoiceNumber);
        $this->assertTrue($invoice->hasPdf());
    }

    public function testIssueInvoiceForOrderRecreatesSubjectWhenStaleAndRetries(): void
    {
        // Regression: a Fakturoid subject_id stored on a User can be deleted
        // out-of-band (manually in the Fakturoid dashboard, or via an account
        // merge). createInvoice then returns 422 "Kontakt neexistuje" wrapped
        // in StaleFakturoidSubjectException. We must clear the stale ID,
        // provision a fresh subject, and retry the invoice creation once.
        $user = $this->createUser();
        $user->setFakturoidSubjectId(30388961, new \DateTimeImmutable());
        $order = $this->createOrder($user);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoiceId = Uuid::v7();

        $fakturoidClient = $this->createMock(FakturoidClient::class);

        // 1st createInvoice call: stale subject, blows up.
        // 2nd call (after recovery): succeeds against the new subject.
        $fakturoidClient->expects($this->exactly(2))
            ->method('createInvoice')
            ->willReturnCallback(function (int $subjectId) use ($order): FakturoidInvoice {
                if (30388961 === $subjectId) {
                    throw new StaleFakturoidSubjectException($subjectId);
                }

                $this->assertSame(99999, $subjectId, 'Retry must use the freshly provisioned subject.');

                return new FakturoidInvoice(77777, 'FV-2025-RECOVERED', $order->firstPaymentPrice);
            });

        $fakturoidClient->expects($this->once())
            ->method('createSubject')
            ->with($user)
            ->willReturn(new FakturoidSubject(99999, 'Jan Novak'));

        $fakturoidClient->expects($this->once())
            ->method('downloadInvoicePdf')
            ->with(77777)
            ->willReturn('%PDF-1.4 recovered');

        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $userRepository = $this->createStub(UserRepository::class);

        $service = new InvoicingService(
            $fakturoidClient,
            $identityProvider,
            $invoiceRepository,
            $userRepository,
            new NullLogger(),
            $this->tempDir,
        );

        $invoice = $service->issueInvoiceForOrder($order, $now);

        $this->assertSame('FV-2025-RECOVERED', $invoice->invoiceNumber);
        $this->assertSame(99999, $user->fakturoidSubjectId, 'User must end up pointing at the new subject.');
    }

    public function testIssueInvoiceForOrderStoresPdfLocally(): void
    {
        $user = $this->createUser();
        $user->setFakturoidSubjectId(11111, new \DateTimeImmutable());
        $order = $this->createOrder($user);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoiceId = Uuid::v7();
        $pdfContent = '%PDF-1.4 test pdf content';

        $fakturoidClient = $this->createStub(FakturoidClient::class);
        $fakturoidClient->method('createInvoice')
            ->willReturn(new FakturoidInvoice(77777, 'FV-2025-0003', 35000));
        $fakturoidClient->method('downloadInvoicePdf')
            ->willReturn($pdfContent);

        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $userRepository = $this->createStub(UserRepository::class);

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

        $fakturoidClient = $this->createStub(FakturoidClient::class);
        $fakturoidClient->method('createInvoice')
            ->willReturn(new FakturoidInvoice(66666, 'FV-2025-0004', 35000));
        $fakturoidClient->method('downloadInvoicePdf')
            ->willReturn('%PDF-1.4 content');

        $identityProvider = $this->createStub(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($invoiceId);

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $userRepository = $this->createStub(UserRepository::class);

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
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
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
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );
    }
}
