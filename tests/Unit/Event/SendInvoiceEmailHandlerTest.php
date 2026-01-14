<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Event\InvoiceCreated;
use App\Event\SendInvoiceEmailHandler;
use App\Repository\InvoiceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
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

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer);
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

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $this->assertSame('Faktura FV-2025-0001 - Fajné Sklady', $sentEmail->getSubject());
    }

    public function testHandlerAttachesPdfWhenAvailable(): void
    {
        $pdfPath = $this->tempDir.'/test_invoice.pdf';
        file_put_contents($pdfPath, '%PDF-1.4 test content');

        $invoice = $this->createInvoice();
        $invoice->attachPdf($pdfPath);

        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $attachments = $sentEmail->getAttachments();
        $this->assertCount(1, $attachments);
    }

    public function testHandlerDoesNotAttachPdfWhenNotAvailable(): void
    {
        $invoice = $this->createInvoice();
        $event = new InvoiceCreated($invoice->id, $invoice->order->id, new \DateTimeImmutable());

        $invoiceRepository = $this->createMock(InvoiceRepository::class);
        $invoiceRepository->method('get')->willReturn($invoice);

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendInvoiceEmailHandler($invoiceRepository, $mailer);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $attachments = $sentEmail->getAttachments();
        $this->assertCount(0, $attachments);
    }

    private function createInvoice(): Invoice
    {
        $owner = new User(
            Uuid::v7(),
            'owner@example.com',
            'password',
            'Owner',
            'User',
            new \DateTimeImmutable(),
        );

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovaci 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
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
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            totalPrice: 35000,
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
