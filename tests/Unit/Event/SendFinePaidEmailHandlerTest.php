<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\FineType;
use App\Event\FinePaid;
use App\Event\SendFinePaidEmailHandler;
use App\Repository\FineRepository;
use App\Service\InvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\OrderStatusUrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendFinePaidEmailHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/fine_paid_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testBundlesFineInvoiceAndMarksEmailed(): void
    {
        // Happy path: the issued fine invoice has a downloadable PDF → attach it
        // and flip emailedAt so the standalone SendInvoiceEmailHandler skips.
        $fine = $this->createPaidFine();
        $invoice = $this->buildInvoice($fine, withPdf: true);

        $sentEmail = $this->dispatch($fine, $invoice);

        $this->assertNotNull($sentEmail);
        $this->assertSame('Pokuta zaplacena — Fajnesklady.cz', $sentEmail->getSubject());
        $this->assertContains(sprintf('faktura_%s.pdf', $invoice->invoiceNumber), $this->attachmentNames($sentEmail));
        $this->assertTrue($invoice->isEmailed());

        \assert($sentEmail instanceof TemplatedEmail);
        $context = $sentEmail->getContext();
        $this->assertSame($invoice->invoiceNumber, $context['invoiceNumber']);
        $this->assertStringContainsString('https://example.com/stav', (string) $context['statusUrl']);
    }

    public function testSendsReceiptWithoutInvoiceWhenIssuanceThrows(): void
    {
        // Fakturoid unreachable — the receipt must still ship, without an attachment.
        $fine = $this->createPaidFine();

        $invoicingService = $this->createStub(InvoicingService::class);
        $invoicingService->method('issueInvoiceForFine')
            ->willThrowException(new \RuntimeException('Fakturoid 503'));

        $sentEmail = $this->dispatch($fine, null, $invoicingService);

        $this->assertNotNull($sentEmail);
        $this->assertSame([], $this->attachmentNames($sentEmail));
        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertNull($sentEmail->getContext()['invoiceNumber']);
    }

    public function testSendsReceiptWithoutInvoiceWhenPdfMissing(): void
    {
        // Invoice issued but its PDF download failed → no attachment, no markEmailed.
        $fine = $this->createPaidFine();
        $invoice = $this->buildInvoice($fine, withPdf: false);

        $sentEmail = $this->dispatch($fine, $invoice);

        $this->assertNotNull($sentEmail);
        $this->assertSame([], $this->attachmentNames($sentEmail));
        $this->assertFalse($invoice->isEmailed());
        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertNull($sentEmail->getContext()['invoiceNumber']);
    }

    /**
     * @return array<string|null>
     */
    private function attachmentNames(Email $email): array
    {
        return array_map(static fn ($a) => $a->getFilename(), $email->getAttachments());
    }

    private function dispatch(Fine $fine, ?Invoice $invoice, ?InvoicingService $invoicingService = null): ?Email
    {
        if (null === $invoicingService) {
            $invoicingService = $this->createStub(InvoicingService::class);
            $invoicingService->method('issueInvoiceForFine')->willReturn($invoice);
        }

        // FineRepository is final — back the real one with a stubbed EntityManager.
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($fine);
        $fineRepository = new FineRepository($entityManager);

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/stav');
        $statusUrlGenerator = new OrderStatusUrlGenerator($urlGenerator, new UriSigner('test-secret'));

        $handler = new SendFinePaidEmailHandler(
            $fineRepository,
            $invoicingService,
            $statusUrlGenerator,
            $mailer,
            new NullLogger(),
        );

        $handler(new FinePaid(
            fineId: $fine->id,
            contractId: $fine->contract->id,
            userId: $fine->user->id,
            amountInHaler: $fine->amountInHaler,
            occurredOn: new \DateTimeImmutable('2025-06-15 12:00:00'),
        ));

        return $sentEmail;
    }

    private function createPaidFine(): Fine
    {
        $user = new User(Uuid::v7(), 'tenant@example.com', 'pw', 'Jan', 'Novák', new \DateTimeImmutable('2025-06-15 12:00:00'));
        $admin = new User(Uuid::v7(), 'admin@example.com', 'pw', 'Admin', 'One', new \DateTimeImmutable('2025-06-15 12:00:00'));

        $place = new Place(
            id: Uuid::v7(),
            name: 'Sklady Praha',
            address: 'Testovací 123',
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
        );

        $order = new Order(
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

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: new \DateTimeImmutable('2025-06-16'),
            endDate: new \DateTimeImmutable('2025-07-16'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $fine = new Fine(
            id: Uuid::v7(),
            contract: $contract,
            user: $user,
            issuedBy: $admin,
            type: FineType::DIRTY_STORAGE,
            amountInHaler: 600_000,
            description: 'Znečištěná skladovací jednotka.',
            issuedAt: new \DateTimeImmutable('2025-06-14 12:00:00'),
            createdAt: new \DateTimeImmutable('2025-06-14 12:00:00'),
        );
        $fine->markPaid(new \DateTimeImmutable('2025-06-15 12:00:00'));

        return $fine;
    }

    private function buildInvoice(Fine $fine, bool $withPdf): Invoice
    {
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $fine->contract->order,
            user: $fine->user,
            fakturoidInvoiceId: 70002,
            invoiceNumber: 'FV-2025-FINE',
            amount: $fine->amountInHaler,
            issuedAt: new \DateTimeImmutable('2025-06-15'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
            fine: $fine,
        );

        if ($withPdf) {
            $path = $this->tempDir.'/fine_invoice.pdf';
            file_put_contents($path, '%PDF-1.4 fine invoice bytes');
            $invoice->attachPdf($path);
        }

        return $invoice;
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
