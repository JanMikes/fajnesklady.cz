<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Service\ContractDocumentGenerator;
use App\Service\Order\OrderReferenceFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ContractDocumentGeneratorTest extends TestCase
{
    private string $tempDir;
    private ContractDocumentGenerator $generator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/contract_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->generator = new ContractDocumentGenerator($this->tempDir, new OrderReferenceFormatter());
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
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

    private function createUser(): User
    {
        return new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novák',
            new \DateTimeImmutable(),
        );
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovací 123',
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

    private function createOrder(User $user, Storage $storage): Order
    {
        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2024-01-15'),
            endDate: new \DateTimeImmutable('2024-02-15'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2024-01-01'),
        );
    }

    private function createContract(Order $order): Contract
    {
        $endDate = $order->endDate;
        \assert(null !== $endDate);

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $endDate,
            createdAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
        );
    }

    private function createTestTemplate(): string
    {
        $templatePath = $this->tempDir.'/template.docx';

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Smlouva o nájmu');
        $section->addText('${TENANT_INFO}');
        $section->addText('Č. ${CONTRACT_NUMBER}');
        $section->addText('Předmět: ${STORAGE_DESCRIPTION}');
        $section->addText('${RENTAL_DURATION_TEXT}');
        $section->addText('V ${CONTRACT_CITY} dne ${CONTRACT_DATE}');

        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($templatePath);

        return $templatePath;
    }

    public function testGenerateCreatesDocumentWithCorrectFilename(): void
    {
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        $templatePath = $this->createTestTemplate();

        $outputPath = $this->generator->generate($contract, $templatePath);

        $this->assertFileExists($outputPath);
        $this->assertStringContainsString('contract_', basename($outputPath));
        $this->assertStringEndsWith('.docx', $outputPath);
    }

    public function testGenerateThrowsExceptionForMissingTemplate(): void
    {
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contract template not found');

        $this->generator->generate($contract, '/nonexistent/template.docx');
    }

    public function testGenerateCreatesOutputDirectory(): void
    {
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        // Use a nested directory that doesn't exist
        $nestedDir = $this->tempDir.'/nested/contracts';
        $generator = new ContractDocumentGenerator($nestedDir, new OrderReferenceFormatter());

        $templatePath = $this->createTestTemplate();
        $outputPath = $generator->generate($contract, $templatePath);

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($outputPath);
    }

    public function testGenerateWithSignaturePathCreatesDocument(): void
    {
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        $templatePath = $this->createTestTemplateWithSignature();
        $signaturePath = $this->createTestSignatureImage();

        $outputPath = $this->generator->generate($contract, $templatePath, $signaturePath);

        $this->assertFileExists($outputPath);
        $this->assertStringEndsWith('.docx', $outputPath);
    }

    public function testGenerateWithNullSignaturePathCreatesDocument(): void
    {
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        $templatePath = $this->createTestTemplateWithSignature();

        $outputPath = $this->generator->generate($contract, $templatePath, null);

        $this->assertFileExists($outputPath);
    }

    public function testGenerateWithNonexistentSignaturePathCreatesDocument(): void
    {
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        $templatePath = $this->createTestTemplateWithSignature();

        $outputPath = $this->generator->generate($contract, $templatePath, '/nonexistent/signature.png');

        $this->assertFileExists($outputPath);
    }

    public function testPersistedContractDateMatchesOrderCreatedAtNotContractCreatedAt(): void
    {
        // The persisted file (portal/admin download) must carry the same
        // ${CONTRACT_DATE} as the byte-identical copy attached to the
        // order-placed and rental-activated e-mails — i.e. the date the
        // customer signed and the contract was legally formed. Using
        // $contract->createdAt (payment-confirmation timestamp) was the
        // historical drift this test guards against.
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $endDate = $order->endDate;
        \assert(null !== $endDate);
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $endDate,
            createdAt: new \DateTimeImmutable('2024-02-10 14:30:00'),
        );

        $templatePath = $this->createTestTemplate();
        $outputPath = $this->generator->generate($contract, $templatePath);
        $xml = $this->extractDocumentXml($outputPath);

        $this->assertStringContainsString('01.01.2024', $xml, '${CONTRACT_DATE} must be $order->createdAt (01.01.2024).');
        $this->assertStringNotContainsString('10.02.2024', $xml, '${CONTRACT_DATE} must not leak $contract->createdAt (10.02.2024).');
    }

    public function testContractNumberMatchesOrderDerivedReference(): void
    {
        // The ${CONTRACT_NUMBER} printed in the DOCX must equal the canonical
        // order reference (order.createdAt + order.id), so the customer reads
        // the identical string on the contract and in every e-mail / status page.
        $tenant = $this->createUser();
        $storage = $this->createStorage();
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        $expectedReference = (new OrderReferenceFormatter())->format($order);

        $templatePath = $this->createTestTemplate();
        $outputPath = $this->generator->generate($contract, $templatePath);
        $xml = $this->extractDocumentXml($outputPath);

        $this->assertStringContainsString($expectedReference, $xml);
        $this->assertSame(
            sprintf('2024-0101-%s', strtoupper(substr($order->id->toRfc4122(), 0, 8))),
            $expectedReference,
        );
    }

    private function extractDocumentXml(string $docxPath): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($docxPath));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertNotFalse($xml, 'word/document.xml not found in '.$docxPath);

        return $xml;
    }

    private function createTestTemplateWithSignature(): string
    {
        $templatePath = $this->tempDir.'/template_with_signature.docx';

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Smlouva o nájmu');
        $section->addText('${TENANT_INFO}');
        $section->addText('Č. ${CONTRACT_NUMBER}');
        $section->addText('Předmět: ${STORAGE_DESCRIPTION}');
        $section->addText('${RENTAL_DURATION_TEXT}');
        $section->addText('V ${CONTRACT_CITY} dne ${CONTRACT_DATE}');
        $section->addText('Podpis: ${SIGNATURE}');

        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($templatePath);

        return $templatePath;
    }

    private function createTestSignatureImage(): string
    {
        $signaturePath = $this->tempDir.'/test_signature.png';
        $image = imagecreatetruecolor(200, 80);
        $white = imagecolorallocate($image, 255, 255, 255);
        \assert(false !== $white);
        imagefill($image, 0, 0, $white);
        imagepng($image, $signaturePath);

        return $signaturePath;
    }
}
