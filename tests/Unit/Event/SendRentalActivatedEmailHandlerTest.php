<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use App\Event\OrderCompleted;
use App\Event\SendRentalActivatedEmailHandler;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Service\InvoicingService;
use App\Service\Order\OrderReferenceFormatter;
use App\Service\OrderEmailAttachments;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Place\PlaceAddressFormatter;
use App\Service\RecurringPaymentCancelUrlGenerator;
use App\Service\StorageMapImageGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendRentalActivatedEmailHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/rental_activated_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testSubjectIsPronajemZahajen(): void
    {
        $contract = $this->createContract();
        $invoice = $this->buildInvoice($contract->order, withPdf: true);
        $sentEmail = $this->dispatch($contract, existingInvoice: $invoice);

        $this->assertNotNull($sentEmail);
        $this->assertNotNull($sentEmail->getSubject());
        $this->assertStringStartsWith('Pronájem zahájen — ', $sentEmail->getSubject());
    }

    public function testBundlesInvoicePdfAndMarksInvoiceEmailedWhenAvailable(): void
    {
        // Happy path: invoice already exists with a downloadable PDF (or was
        // just issued upstream). The handler must (1) attach the PDF bytes
        // inline, (2) flip Invoice.emailedAt so the standalone
        // SendInvoiceEmailHandler skips when InvoiceCreated drains next.
        $contract = $this->createContract();
        $invoice = $this->buildInvoice($contract->order, withPdf: true);

        $sentEmail = $this->dispatch($contract, existingInvoice: $invoice);

        $this->assertNotNull($sentEmail);
        $names = $this->attachmentNames($sentEmail);
        $this->assertContains(sprintf('faktura_%s.pdf', $invoice->invoiceNumber), $names);
        $this->assertTrue($invoice->isEmailed());

        \assert($sentEmail instanceof TemplatedEmail);
        $context = $sentEmail->getContext();
        $this->assertTrue($context['hasInvoiceAttachment']);
        $this->assertSame($invoice->invoiceNumber, $context['invoiceNumber']);
    }

    public function testIssuesInvoiceWhenNoneExistsForPaidOrder(): void
    {
        // No invoice yet on entry → handler calls InvoicingService to issue
        // one synchronously, then attaches & marks emailed.
        $contract = $this->createContract();
        $issuedInvoice = $this->buildInvoice($contract->order, withPdf: true);

        $invoicingService = $this->createMock(InvoicingService::class);
        $invoicingService->expects($this->once())
            ->method('issueInvoiceForOrder')
            ->with($contract->order)
            ->willReturn($issuedInvoice);

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('findByOrder')->willReturn(null);

        $sentEmail = $this->dispatch(
            $contract,
            invoiceRepository: $invoiceRepository,
            invoicingService: $invoicingService,
        );

        $this->assertNotNull($sentEmail);
        $names = $this->attachmentNames($sentEmail);
        $this->assertContains(sprintf('faktura_%s.pdf', $issuedInvoice->invoiceNumber), $names);
        $this->assertTrue($issuedInvoice->isEmailed());
    }

    public function testDoesNotIssueInvoiceForExternalPaymentOrder(): void
    {
        // EXTERNAL paymentMethod = admin recorded "paid" administratively
        // (paper-contract migration or bank-transfer prepayment) — no money
        // flowed through the system. The rental-activated e-mail still ships
        // (the contract is real, just unpaid-via-system), but no invoice is
        // issued; the customer will be invoiced when an actual payment is
        // received via the recurring path.
        $contract = $this->createContract(paymentMethod: PaymentMethod::EXTERNAL);

        $invoicingService = $this->createMock(InvoicingService::class);
        $invoicingService->expects($this->never())->method('issueInvoiceForOrder');

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('findByOrder')->willReturn(null);

        $sentEmail = $this->dispatch(
            $contract,
            invoiceRepository: $invoiceRepository,
            invoicingService: $invoicingService,
        );

        $this->assertNotNull($sentEmail);
        $names = $this->attachmentNames($sentEmail);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('faktura_', (string) $name);
        }

        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertFalse($sentEmail->getContext()['hasInvoiceAttachment']);
    }

    public function testSkipsInvoiceForFreeOrder(): void
    {
        // firstPaymentPrice = 0 → handler never calls InvoicingService,
        // never attaches an invoice; the rental-activated e-mail still
        // goes out with all the other documents.
        $contract = $this->createContract(firstPaymentPrice: 0);

        $invoicingService = $this->createMock(InvoicingService::class);
        $invoicingService->expects($this->never())->method('issueInvoiceForOrder');

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('findByOrder')->willReturn(null);

        $sentEmail = $this->dispatch(
            $contract,
            invoiceRepository: $invoiceRepository,
            invoicingService: $invoicingService,
        );

        $this->assertNotNull($sentEmail);
        $names = $this->attachmentNames($sentEmail);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('faktura_', (string) $name);
        }

        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertFalse($sentEmail->getContext()['hasInvoiceAttachment']);
    }

    public function testSendsEmailWithoutInvoiceWhenIssuanceThrows(): void
    {
        // Fakturoid was unreachable. The rental-activated e-mail must still
        // ship — without the invoice. emailedAt stays null so the standalone
        // fallback (or the IssueMissingInvoicesCommand cron) can retry.
        $contract = $this->createContract();

        $invoicingService = $this->createStub(InvoicingService::class);
        $invoicingService->method('issueInvoiceForOrder')
            ->willThrowException(new \RuntimeException('Fakturoid 503'));

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('findByOrder')->willReturn(null);

        $sentEmail = $this->dispatch(
            $contract,
            invoiceRepository: $invoiceRepository,
            invoicingService: $invoicingService,
        );

        $this->assertNotNull($sentEmail);
        $names = $this->attachmentNames($sentEmail);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('faktura_', (string) $name);
        }

        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertFalse($sentEmail->getContext()['hasInvoiceAttachment']);
    }

    public function testSendsEmailWithoutInvoiceWhenInvoiceHasNoDownloadablePdf(): void
    {
        // Invoice exists but the PDF download (Fakturoid → disk) failed,
        // so $invoice->pdfPath is null. Don't attach, don't markEmailed —
        // standalone fallback handles delivery as text-only.
        $contract = $this->createContract();
        $invoice = $this->buildInvoice($contract->order, withPdf: false);

        $sentEmail = $this->dispatch($contract, existingInvoice: $invoice);

        $this->assertNotNull($sentEmail);
        $names = $this->attachmentNames($sentEmail);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('faktura_', (string) $name);
        }
        $this->assertFalse($invoice->isEmailed());

        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertFalse($sentEmail->getContext()['hasInvoiceAttachment']);
    }

    /**
     * @return array<string|null>
     */
    private function attachmentNames(Email $email): array
    {
        return array_map(static fn ($a) => $a->getFilename(), $email->getAttachments());
    }

    private function buildInvoice(Order $order, bool $withPdf): Invoice
    {
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            fakturoidInvoiceId: 99999,
            invoiceNumber: 'FV-2025-0001',
            amount: $order->firstPaymentPrice,
            issuedAt: new \DateTimeImmutable('2025-06-15'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );

        if ($withPdf) {
            $path = $this->tempDir.'/invoice_'.$invoice->invoiceNumber.'.pdf';
            file_put_contents($path, '%PDF-1.4 invoice bytes');
            $invoice->attachPdf($path);
        }

        return $invoice;
    }

    private function dispatch(
        Contract $contract,
        ?Invoice $existingInvoice = null,
        ?InvoicingService $invoicingService = null,
        ?InvoiceRepository $invoiceRepository = null,
    ): ?Email {
        if (null === $invoiceRepository) {
            $invoiceRepository = $this->createStub(InvoiceRepository::class);
            $invoiceRepository->method('findByOrder')->willReturn($existingInvoice);
        }

        if (null === $invoicingService) {
            $invoicingService = $this->createMock(InvoicingService::class);
            $invoicingService->expects($this->never())->method('issueInvoiceForOrder');
        }

        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('get')->willReturn($contract);

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/portal');

        $uriSigner = new UriSigner('test-secret');
        $statusUrlGenerator = new OrderStatusUrlGenerator($urlGenerator, $uriSigner);
        $cancelUrlGenerator = new RecurringPaymentCancelUrlGenerator($urlGenerator, $uriSigner);

        $mapGenerator = $this->createStub(StorageMapImageGenerator::class);
        $mapGenerator->method('generate')->willReturn(null);

        // Legal-pack attachments are exercised by OrderEmailAttachmentsTest;
        // here we install a no-op stub so we can focus on invoice-bundling.
        $attachments = $this->createStub(OrderEmailAttachments::class);
        $attachments->method('attachLegalDocuments')->willReturn([
            'hasContract' => true,
            'hasVop' => true,
            'hasConsumerNotice' => true,
            'hasRecurringTerms' => false,
        ]);

        $handler = new SendRentalActivatedEmailHandler(
            $contractRepository,
            $invoiceRepository,
            $invoicingService,
            $attachments,
            $mailer,
            $statusUrlGenerator,
            $cancelUrlGenerator,
            $mapGenerator,
            new PlaceAddressFormatter(),
            new OrderReferenceFormatter(),
            new MockClock('2025-06-15 12:00:00'),
            new NullLogger(),
            $this->tempDir,
        );

        $event = new OrderCompleted($contract->order->id, $contract->id, new \DateTimeImmutable());
        $handler($event);

        return $sentEmail;
    }

    private function createContract(int $firstPaymentPrice = 35000, ?PaymentMethod $paymentMethod = null): Contract
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
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0, 'normalized' => true],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $user = new User(Uuid::v7(), 'tenant@example.com', 'pw', 'Jan', 'Novak', new \DateTimeImmutable());

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            firstPaymentPrice: $firstPaymentPrice,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );

        if (null !== $paymentMethod) {
            $order->setPaymentMethod($paymentMethod);
        }

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
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
