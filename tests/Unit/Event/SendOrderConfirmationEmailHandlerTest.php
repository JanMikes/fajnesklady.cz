<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Enum\SigningMethod;
use App\Event\OrderPlaced;
use App\Event\SendOrderConfirmationEmailHandler;
use App\Repository\OrderRepository;
use App\Service\ContractDocumentGenerator;
use App\Service\DocumentPdfConverter;
use App\Service\OrderStatusUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendOrderConfirmationEmailHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/order_email_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir.'/public/documents', 0755, true);

        // Create static document files
        file_put_contents($this->tempDir.'/public/documents/vop.pdf', '%PDF-vop');
        file_put_contents($this->tempDir.'/public/documents/pouceni-spotrebitele.pdf', '%PDF-consumer');
        file_put_contents($this->tempDir.'/public/documents/podminky-opakovanych-plateb.pdf', '%PDF-recurring');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testAttachesVopAndConsumerNoticeForLimitedRental(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'), withSignature: true);
        $sentEmail = $this->sendEmail($order);

        $names = $this->attachmentNames($sentEmail);

        $this->assertContains('vop.pdf', $names);
        $this->assertContains('pouceni-spotrebitele.pdf', $names);
        $this->assertNotContains('podminky-opakovanych-plateb.pdf', $names);
    }

    public function testAttachesRecurringPaymentsTermsForUnlimitedRental(): void
    {
        $order = $this->createOrder(endDate: null, withSignature: true);
        $sentEmail = $this->sendEmail($order);

        $names = $this->attachmentNames($sentEmail);

        $this->assertContains('vop.pdf', $names);
        $this->assertContains('pouceni-spotrebitele.pdf', $names);
        $this->assertContains('podminky-opakovanych-plateb.pdf', $names);
    }

    public function testDoesNotAttachOperatingRulesOrMap(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'), withSignature: true);
        $order->storage->getPlace()->updateOperatingRules('places/test/provozni-rad.pdf', new \DateTimeImmutable());

        $sentEmail = $this->sendEmail($order);

        $names = $this->attachmentNames($sentEmail);

        // provozni-rad and map are sent in the post-payment "Smlouva připravena" email,
        // not in the placement confirmation.
        foreach ($names as $name) {
            $this->assertStringNotContainsString('provozni-rad', (string) $name);
            $this->assertStringNotContainsString('mapa', (string) $name);
        }
    }

    public function testAttachesContractDocxWhenOrderIsSigned(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'), withSignature: true);

        $contractGenerator = $this->createStub(ContractDocumentGenerator::class);
        $contractGenerator->method('renderBytesForOrder')->willReturn('PK fake docx bytes');

        $sentEmail = $this->sendEmail($order, $contractGenerator);

        $names = $this->attachmentNames($sentEmail);
        $contractAttachments = array_filter($names, fn ($n) => str_starts_with((string) $n, 'smlouva-'));

        $this->assertCount(1, $contractAttachments, 'Expected exactly one smlouva-* attachment');
    }

    public function testAttachesContractAsPdfWhenConversionAvailable(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'), withSignature: true);

        $contractGenerator = $this->createStub(ContractDocumentGenerator::class);
        $contractGenerator->method('renderBytesForOrder')->willReturn('PK fake docx bytes');

        $pdfConverter = $this->createStub(DocumentPdfConverter::class);
        $pdfConverter->method('convertBytesToPdfBytes')->willReturn('%PDF-1.4 fake');

        $sentEmail = $this->sendEmail($order, $contractGenerator, $pdfConverter);

        $names = $this->attachmentNames($sentEmail);
        $contractAttachments = array_values(array_filter($names, fn ($n) => str_starts_with((string) $n, 'smlouva-')));

        $this->assertCount(1, $contractAttachments);
        $this->assertStringEndsWith('.pdf', (string) $contractAttachments[0]);
    }

    public function testSkipsContractAttachmentWhenOrderHasNoSignature(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'), withSignature: false);

        $contractGenerator = $this->createMock(ContractDocumentGenerator::class);
        $contractGenerator->expects($this->never())->method('renderBytesForOrder');

        $sentEmail = $this->sendEmail($order, $contractGenerator);

        $names = $this->attachmentNames($sentEmail);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('smlouva-', (string) $name);
        }
    }

    /**
     * @return array<string|null>
     */
    private function attachmentNames(Email $email): array
    {
        return array_map(fn ($a) => $a->getFilename(), $email->getAttachments());
    }

    private function sendEmail(
        Order $order,
        ?ContractDocumentGenerator $contractGenerator = null,
        ?DocumentPdfConverter $pdfConverter = null,
    ): Email {
        $event = new OrderPlaced($order->id, new \DateTimeImmutable());

        $orderRepository = $this->createStub(OrderRepository::class);
        $orderRepository->method('get')->willReturn($order);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/objednavka/abc/stav');

        $statusUrlGenerator = new OrderStatusUrlGenerator($urlGenerator, new UriSigner('test-secret'));

        if (null === $contractGenerator) {
            $contractGenerator = $this->createStub(ContractDocumentGenerator::class);
            $contractGenerator->method('renderBytesForOrder')->willReturn('PK fake docx bytes');
        }

        if (null === $pdfConverter) {
            // Default: simulate conversion failure → handler attaches DOCX,
            // matching the existing tests that look for smlouva-*.docx names.
            $pdfConverter = $this->createStub(DocumentPdfConverter::class);
            $pdfConverter->method('convertBytesToPdfBytes')->willReturn(null);
        }

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendOrderConfirmationEmailHandler(
            $orderRepository,
            $mailer,
            $statusUrlGenerator,
            $contractGenerator,
            $pdfConverter,
            $this->tempDir,
            $this->tempDir.'/template.docx',
        );
        $handler($event);

        $this->assertNotNull($sentEmail);

        return $sentEmail;
    }

    private function createOrder(?\DateTimeImmutable $endDate, bool $withSignature): Order
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test 123',
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

        $user = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novak',
            new \DateTimeImmutable(),
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: null === $endDate ? RentalType::UNLIMITED : RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable(),
            endDate: $endDate,
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );

        if ($withSignature) {
            $order->attachSignature(
                signaturePath: '/tmp/signature.png',
                signingMethod: SigningMethod::DRAW,
                typedName: null,
                styleId: null,
                signingPlace: 'Praha',
                now: new \DateTimeImmutable(),
            );
        }

        return $order;
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
