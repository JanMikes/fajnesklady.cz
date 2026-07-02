<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Event\OnboardingDebtPaid;
use App\Event\SendOnboardingDebtPaidEmailHandler;
use App\Repository\OrderRepository;
use App\Service\InvoicingService;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Place\PlaceAddressFormatter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendOnboardingDebtPaidEmailHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/debt_paid_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testSubjectAndStorageContext(): void
    {
        $order = $this->createDebtOrder();
        $sentEmail = $this->dispatch($order, $this->buildInvoice($order, withPdf: true));

        $this->assertNotNull($sentEmail);
        $this->assertStringStartsWith('Dluh uhrazen — ', (string) $sentEmail->getSubject());

        \assert($sentEmail instanceof TemplatedEmail);
        $context = $sentEmail->getContext();
        $this->assertSame('A1', $context['storageNumber']);
        $this->assertSame('Small Box', $context['storageTypeName']);
        $this->assertSame('1 200', $context['amountCzk']);
    }

    public function testBundlesDebtInvoiceAndMarksEmailed(): void
    {
        // Happy path: the issued debt invoice has a downloadable PDF → attach it
        // and flip emailedAt so the standalone SendInvoiceEmailHandler skips.
        $order = $this->createDebtOrder();
        $invoice = $this->buildInvoice($order, withPdf: true);

        $sentEmail = $this->dispatch($order, $invoice);

        $this->assertNotNull($sentEmail);
        $this->assertContains(sprintf('faktura_%s.pdf', $invoice->invoiceNumber), $this->attachmentNames($sentEmail));
        $this->assertTrue($invoice->isEmailed());

        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertSame($invoice->invoiceNumber, $sentEmail->getContext()['invoiceNumber']);
    }

    public function testAwaitingFirstPaymentTrueForStandardBilling(): void
    {
        // Order still payable (RESERVED-ish) → customer must still pay the first rent.
        $order = $this->createDebtOrder();
        $sentEmail = $this->dispatch($order, $this->buildInvoice($order, withPdf: true));

        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertTrue($sentEmail->getContext()['awaitingFirstPayment']);
    }

    public function testAwaitingFirstPaymentFalseWhenOrderCompleted(): void
    {
        // Free/prepaid debt order auto-completes → rental active, no first rent due.
        $order = $this->createDebtOrder();
        $order->complete(Uuid::v7(), new \DateTimeImmutable('2025-06-15 12:00:00'));

        $sentEmail = $this->dispatch($order, $this->buildInvoice($order, withPdf: true));

        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertFalse($sentEmail->getContext()['awaitingFirstPayment']);
    }

    public function testSendsReceiptWithoutInvoiceWhenIssuanceThrows(): void
    {
        // Fakturoid unreachable — the receipt must still ship, without an attachment.
        $order = $this->createDebtOrder();

        $invoicingService = $this->createStub(InvoicingService::class);
        $invoicingService->method('issueInvoiceForDebt')
            ->willThrowException(new \RuntimeException('Fakturoid 503'));

        $sentEmail = $this->dispatch($order, null, $invoicingService);

        $this->assertNotNull($sentEmail);
        foreach ($this->attachmentNames($sentEmail) as $name) {
            $this->assertStringStartsNotWith('faktura_', (string) $name);
        }
        \assert($sentEmail instanceof TemplatedEmail);
        $this->assertNull($sentEmail->getContext()['invoiceNumber']);
    }

    public function testSendsReceiptWithoutInvoiceWhenPdfMissing(): void
    {
        // Invoice issued but its PDF download failed → no attachment, no markEmailed.
        $order = $this->createDebtOrder();
        $invoice = $this->buildInvoice($order, withPdf: false);

        $sentEmail = $this->dispatch($order, $invoice);

        $this->assertNotNull($sentEmail);
        foreach ($this->attachmentNames($sentEmail) as $name) {
            $this->assertStringStartsNotWith('faktura_', (string) $name);
        }
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

    private function dispatch(Order $order, ?Invoice $invoice, ?InvoicingService $invoicingService = null): ?Email
    {
        if (null === $invoicingService) {
            $invoicingService = $this->createStub(InvoicingService::class);
            $invoicingService->method('issueInvoiceForDebt')->willReturn($invoice);
        }

        $orderRepository = $this->createStub(OrderRepository::class);
        $orderRepository->method('get')->willReturn($order);

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/stav');
        $statusUrlGenerator = new OrderStatusUrlGenerator($urlGenerator, new UriSigner('test-secret'));

        $handler = new SendOnboardingDebtPaidEmailHandler(
            $orderRepository,
            $invoicingService,
            $statusUrlGenerator,
            new PlaceAddressFormatter(),
            $mailer,
            new NullLogger(),
        );

        $handler(new OnboardingDebtPaid(
            $order->id,
            $order->user->id,
            $order->onboardingDebtInHaler ?? 0,
            new \DateTimeImmutable('2025-06-15 12:00:00'),
        ));

        return $sentEmail;
    }

    private function createDebtOrder(): Order
    {
        $user = new User(Uuid::v7(), 'tenant@example.com', 'pw', 'Jan', 'Novák', new \DateTimeImmutable('2025-06-15 12:00:00'));
        $owner = new User(Uuid::v7(), 'owner@example.com', 'pw', 'Test', 'Owner', new \DateTimeImmutable('2025-06-15 12:00:00'));

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
            owner: $owner,
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
        $order->setOnboardingDebt(120_000); // 1 200 Kč
        $order->markDebtPaid(new \DateTimeImmutable('2025-06-15 11:00:00'));

        return $order;
    }

    private function buildInvoice(Order $order, bool $withPdf): Invoice
    {
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            fakturoidInvoiceId: 70001,
            invoiceNumber: 'FV-2025-DEBT',
            amount: $order->onboardingDebtInHaler ?? 0,
            issuedAt: new \DateTimeImmutable('2025-06-15'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );

        if ($withPdf) {
            $path = $this->tempDir.'/debt_invoice.pdf';
            file_put_contents($path, '%PDF-1.4 debt invoice bytes');
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
