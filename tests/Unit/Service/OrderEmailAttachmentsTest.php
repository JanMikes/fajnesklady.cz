<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Enum\SigningMethod;
use App\Repository\ContractRepository;
use App\Service\ContractDocumentGenerator;
use App\Service\DocumentPdfConverter;
use App\Service\OrderEmailAttachmentsService;
use App\Service\Vop\VopDocumentGenerator;
use App\Service\Vop\VopPdfStamper;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

class OrderEmailAttachmentsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/order_attachments_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir.'/public/documents', 0755, true);

        // Static legal docs that the service attaches from disk.
        file_put_contents($this->tempDir.'/public/documents/pouceni-spotrebitele.pdf', '%PDF-consumer');
        file_put_contents($this->tempDir.'/public/documents/podminky-opakovanych-plateb.pdf', '%PDF-recurring');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testAttachesContractVopAndConsumerNoticeForLimitedRental(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('2025-07-15'), withSignature: true);
        $email = new TemplatedEmail();

        $context = $this->buildService()->attachLegalDocuments($email, $order);

        $names = $this->attachmentNames($email);
        $this->assertContains('pouceni-spotrebitele.pdf', $names);
        $this->assertNotEmpty(array_filter($names, static fn ($n) => str_starts_with((string) $n, 'vop-')));
        $this->assertNotContains('podminky-opakovanych-plateb.pdf', $names, 'Recurring terms are unlimited-only.');

        $this->assertTrue($context['hasContract']);
        $this->assertTrue($context['hasVop']);
        $this->assertTrue($context['hasConsumerNotice']);
        $this->assertFalse($context['hasRecurringTerms']);
    }

    public function testAttachesRecurringTermsForUnlimitedRental(): void
    {
        $order = $this->createOrder(endDate: null, withSignature: true);
        $email = new TemplatedEmail();

        $context = $this->buildService()->attachLegalDocuments($email, $order);

        $names = $this->attachmentNames($email);
        $this->assertContains('podminky-opakovanych-plateb.pdf', $names);
        $this->assertTrue($context['hasRecurringTerms']);
    }

    public function testContractFilenameMatchesDocumentNumberPrintedInsideTheDocument(): void
    {
        // The number printed as ${CONTRACT_NUMBER} inside the DOCX (rendered
        // by ContractDocumentGenerator from $order->id + $order->createdAt)
        // must match the filename customers see in their mail client, so
        // they can identify which file corresponds to which contract.
        $order = $this->createOrder(endDate: new \DateTimeImmutable('2025-07-15'), withSignature: true);
        $email = new TemplatedEmail();

        $contractGenerator = $this->createMock(ContractDocumentGenerator::class);
        $contractGenerator->method('renderBytesForOrder')->willReturn('PK fake docx');
        $contractGenerator->method('formatDocumentNumberForOrder')->willReturn('2025-0615-DEADBEEF');

        $pdfConverter = $this->createStub(DocumentPdfConverter::class);
        $pdfConverter->method('convertBytesToPdfBytes')->willReturn('%PDF-1.4 fake');

        $service = $this->buildService(contractGenerator: $contractGenerator, pdfConverter: $pdfConverter);
        $service->attachLegalDocuments($email, $order);

        $names = $this->attachmentNames($email);
        $this->assertContains('smlouva_2025-0615-DEADBEEF.pdf', $names);
    }

    public function testFallsBackToDocxWhenPdfConversionFails(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('2025-07-15'), withSignature: true);
        $email = new TemplatedEmail();

        $pdfConverter = $this->createStub(DocumentPdfConverter::class);
        $pdfConverter->method('convertBytesToPdfBytes')->willReturn(null);

        $this->buildService(pdfConverter: $pdfConverter)->attachLegalDocuments($email, $order);

        $names = $this->attachmentNames($email);
        $contractAttachments = array_values(array_filter($names, static fn ($n) => str_starts_with((string) $n, 'smlouva_')));
        $this->assertCount(1, $contractAttachments);
        $this->assertStringEndsWith('.docx', (string) $contractAttachments[0]);
    }

    public function testSkipsContractWhenOrderHasNoSignature(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('2025-07-15'), withSignature: false);
        $email = new TemplatedEmail();

        $contractGenerator = $this->createMock(ContractDocumentGenerator::class);
        $contractGenerator->expects($this->never())->method('renderBytesForOrder');

        $context = $this->buildService(contractGenerator: $contractGenerator)->attachLegalDocuments($email, $order);

        $names = $this->attachmentNames($email);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('smlouva_', (string) $name);
        }
        $this->assertFalse($context['hasContract']);
    }

    public function testSkipsVopWhenStamperReturnsNull(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('2025-07-15'), withSignature: true);
        $email = new TemplatedEmail();

        $vopStamper = $this->createStub(VopPdfStamper::class);
        $vopStamper->method('stampSignedPdfBytes')->willReturn(null);

        $context = $this->buildService(vopStamper: $vopStamper)->attachLegalDocuments($email, $order);

        $names = $this->attachmentNames($email);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('vop-', (string) $name);
        }
        $this->assertFalse($context['hasVop']);
    }

    /**
     * @return array<string|null>
     */
    private function attachmentNames(Email $email): array
    {
        return array_map(static fn ($a) => $a->getFilename(), $email->getAttachments());
    }

    private function buildService(
        ?ContractDocumentGenerator $contractGenerator = null,
        ?DocumentPdfConverter $pdfConverter = null,
        ?VopPdfStamper $vopStamper = null,
    ): OrderEmailAttachmentsService {
        if (null === $contractGenerator) {
            $contractGenerator = $this->createStub(ContractDocumentGenerator::class);
            $contractGenerator->method('renderBytesForOrder')->willReturn('PK fake docx');
            $contractGenerator->method('formatDocumentNumberForOrder')->willReturn('2025-0615-TESTABCD');
        }

        if (null === $pdfConverter) {
            $pdfConverter = $this->createStub(DocumentPdfConverter::class);
            $pdfConverter->method('convertBytesToPdfBytes')->willReturn('%PDF-1.4 fake');
        }

        $vopGenerator = $this->createStub(VopDocumentGenerator::class);
        $vopGenerator->method('generate')->willReturn($this->tempDir.'/vop_stub.docx');

        if (null === $vopStamper) {
            $vopStamper = $this->createStub(VopPdfStamper::class);
            $vopStamper->method('stampSignedPdfBytes')->willReturn('%PDF-vop');
        }

        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('findByOrder')->willReturn(null);

        return new OrderEmailAttachmentsService(
            $contractGenerator,
            $pdfConverter,
            $vopGenerator,
            $vopStamper,
            $contractRepository,
            $this->tempDir,
            $this->tempDir.'/template.docx',
            $this->tempDir.'/vop_template.docx',
            $this->tempDir.'/contracts',
        );
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

        $user = new User(Uuid::v7(), 'tenant@example.com', 'pw', 'Jan', 'Novak', new \DateTimeImmutable());

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
