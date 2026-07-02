<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Event\InvoiceCreated;
use App\Event\SendInvoiceEmailHandler;
use App\Repository\InvoiceRepository;
use App\Service\OrderStatusUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendInvoiceEmailHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/invoice_email_test_'.uniqid();
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

    public function testHandlerSendsEmailWithCorrectRecipient(): void
    {
        $invoice = $this->createInvoice();
        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->expects($this->once())
            ->method('get')
            ->with($invoice->id)
            ->willReturn($invoice);

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$sentEmail) {
                $sentEmail = $email;
            });

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer, $this->createStatusUrlGenerator(), new MockClock('2025-06-15 12:00:00'));
        $handler($event);

        $this->assertNotNull($sentEmail);
        $to = $sentEmail->getTo();
        $this->assertCount(1, $to);
        $this->assertSame('tenant@example.com', $to[0]->getAddress());
        $this->assertSame('Jan Novak', $to[0]->getName());
    }

    public function testHandlerSendsEmailWithCorrectSubject(): void
    {
        $invoice = $this->createInvoice();
        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer, $this->createStatusUrlGenerator(), new MockClock('2025-06-15 12:00:00'));
        $handler($event);

        $this->assertNotNull($sentEmail);
        $this->assertSame('Faktura FV-2025-0001 - Fajnesklady.cz', $sentEmail->getSubject());
    }

    public function testHandlerAttachesPdfWhenAvailable(): void
    {
        $pdfPath = $this->tempDir.'/test_invoice.pdf';
        file_put_contents($pdfPath, '%PDF-1.4 test content');

        $invoice = $this->createInvoice();
        $invoice->attachPdf($pdfPath);

        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer, $this->createStatusUrlGenerator(), new MockClock('2025-06-15 12:00:00'));
        $handler($event);

        $this->assertNotNull($sentEmail);
        $attachments = $sentEmail->getAttachments();
        $this->assertCount(1, $attachments);

        /** @var \Symfony\Bridge\Twig\Mime\TemplatedEmail $sentEmail */
        $context = $sentEmail->getContext();
        $this->assertTrue($context['hasPdfAttachment']);
    }

    public function testHandlerDoesNotAttachPdfWhenNotAvailable(): void
    {
        $invoice = $this->createInvoice();
        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer, $this->createStatusUrlGenerator(), new MockClock('2025-06-15 12:00:00'));
        $handler($event);

        $this->assertNotNull($sentEmail);
        $attachments = $sentEmail->getAttachments();
        $this->assertCount(0, $attachments);

        /** @var \Symfony\Bridge\Twig\Mime\TemplatedEmail $sentEmail */
        $context = $sentEmail->getContext();
        $this->assertFalse($context['hasPdfAttachment']);
    }

    public function testHandlerSkipsWhenInvoiceAlreadyEmailed(): void
    {
        // When SendRentalActivatedEmailHandler bundled the invoice into the
        // post-payment e-mail, it marked emailedAt before the InvoiceCreated
        // event was dispatched. By the time this handler runs, the standalone
        // delivery is redundant and must be suppressed.
        $invoice = $this->createInvoice();
        $invoice->markEmailed(new \DateTimeImmutable('2025-06-15 11:59:30'));

        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer, $this->createStatusUrlGenerator(), new MockClock('2025-06-15 12:00:00'));
        $handler($event);
    }

    public function testHandlerMarksInvoiceEmailedWhenSending(): void
    {
        // The fallback / recurring path: emailedAt is null on entry, the
        // handler sends the standalone e-mail, and stamps emailedAt with the
        // clock's now so future re-dispatches (e.g. messenger retries) skip.
        $invoice = $this->createInvoice();
        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $mailer = $this->createStub(MailerInterface::class);

        $clock = new MockClock('2025-06-15 12:34:56');
        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer, $this->createStatusUrlGenerator(), $clock);
        $handler($event);

        $this->assertTrue($invoice->isEmailed());
        $this->assertEquals(new \DateTimeImmutable('2025-06-15 12:34:56'), $invoice->emailedAt);
    }

    private function createStatusUrlGenerator(): OrderStatusUrlGenerator
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/objednavka/abc/stav');

        return new OrderStatusUrlGenerator($urlGenerator, new UriSigner('test-secret'));
    }

    private function createInvoice(): Invoice
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
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $tenant = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novak',
            new \DateTimeImmutable(),
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );

        return new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $tenant,
            fakturoidInvoiceId: 99999,
            invoiceNumber: 'FV-2025-0001',
            amount: 35000,
            issuedAt: new \DateTimeImmutable('2025-06-15'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );
    }
}
