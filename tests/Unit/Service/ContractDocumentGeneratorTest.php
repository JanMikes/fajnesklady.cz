<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Service\ContractDocumentGenerator;
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
        $this->generator = new ContractDocumentGenerator($this->tempDir);
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
        $user = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novák',
            new \DateTimeImmutable(),
        );

        return $user;
    }

    private function createPlace(User $owner): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovací 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorageType(Place $place): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            width: 100,
            height: 200,
            length: 150,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorage(StorageType $storageType): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createOrder(User $user, Storage $storage): Order
    {
        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2024-01-15'),
            endDate: new \DateTimeImmutable('2024-02-15'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2024-01-01'),
        );
    }

    private function createContract(Order $order): Contract
    {
        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            rentalType: $order->rentalType,
            startDate: $order->startDate,
            endDate: $order->endDate,
            createdAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
        );
    }

    private function createTestTemplate(): string
    {
        $templatePath = $this->tempDir.'/template.docx';

        // Create a simple DOCX template using PhpWord
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Smlouva o pronájmu');
        $section->addText('Nájemce: ${TENANT_NAME}');
        $section->addText('Email: ${TENANT_EMAIL}');
        $section->addText('Telefon: ${TENANT_PHONE}');
        $section->addText('Box: ${STORAGE_NUMBER}');
        $section->addText('Typ: ${STORAGE_TYPE}');
        $section->addText('Rozměry: ${STORAGE_DIMENSIONS}');
        $section->addText('Místo: ${PLACE_NAME}');
        $section->addText('Adresa: ${PLACE_ADDRESS}');
        $section->addText('Od: ${START_DATE}');
        $section->addText('Do: ${END_DATE}');
        $section->addText('Typ nájmu: ${RENTAL_TYPE}');
        $section->addText('Cena: ${PRICE}');
        $section->addText('Datum smlouvy: ${CONTRACT_DATE}');
        $section->addText('Číslo smlouvy: ${CONTRACT_NUMBER}');

        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($templatePath);

        return $templatePath;
    }

    public function testGenerateCreatesDocumentWithCorrectFilename(): void
    {
        $owner = $this->createUser();
        $tenant = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);
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
        $owner = $this->createUser();
        $tenant = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contract template not found');

        $this->generator->generate($contract, '/nonexistent/template.docx');
    }

    public function testGenerateCreatesOutputDirectory(): void
    {
        $owner = $this->createUser();
        $tenant = $this->createUser();
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType);
        $order = $this->createOrder($tenant, $storage);
        $contract = $this->createContract($order);

        // Use a nested directory that doesn't exist
        $nestedDir = $this->tempDir.'/nested/contracts';
        $generator = new ContractDocumentGenerator($nestedDir);

        $templatePath = $this->createTestTemplate();
        $outputPath = $generator->generate($contract, $templatePath);

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($outputPath);
    }
}
