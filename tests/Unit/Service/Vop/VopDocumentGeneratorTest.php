<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vop;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Service\Vop\VopDocumentGenerator;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Uid\Uuid;

final class VopDocumentGeneratorTest extends TestCase
{
    private string $tempDir;
    private string $templatePath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/vop_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->templatePath = $this->tempDir.'/vop_template.docx';
        $this->writeTemplate($this->templatePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testSubstitutesBothPlaceholdersWhenPlaceHasOperatingRules(): void
    {
        $order = $this->makeOrder(operatingRulesPath: 'places/foo/operating-rules/x.pdf');
        $placeId = $order->storage->getPlace()->id->toRfc4122();
        $generator = $this->makeGenerator();

        $outputPath = $generator->generate($order, $this->templatePath);

        $body = $this->readDocumentXml($outputPath);

        self::assertStringContainsString(sprintf('https://test.example/pobocka/%s/cenik', $placeId), $body);
        self::assertStringContainsString('https://test.example/uploads/places/foo/operating-rules/x.pdf', $body);
        self::assertStringNotContainsString('PRICELIST_URL', $body);
        self::assertStringNotContainsString('OPERATING_RULES_URL', $body);
    }

    public function testFallsBackToPlaceDetailWhenNoOperatingRulesUploaded(): void
    {
        $order = $this->makeOrder(operatingRulesPath: null);
        $placeId = $order->storage->getPlace()->id->toRfc4122();
        $generator = $this->makeGenerator();

        $outputPath = $generator->generate($order, $this->templatePath);

        $body = $this->readDocumentXml($outputPath);

        self::assertStringContainsString(sprintf('https://test.example/pobocka/%s/cenik', $placeId), $body);
        self::assertStringContainsString(sprintf('https://test.example/pobocka/%s', $placeId), $body);
        self::assertStringNotContainsString('OPERATING_RULES_URL', $body);
    }

    public function testThrowsWhenTemplateMissing(): void
    {
        $generator = $this->makeGenerator();
        $order = $this->makeOrder(operatingRulesPath: null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/VOP template not found/');

        $generator->generate($order, $this->tempDir.'/missing.docx');
    }

    public function testPathForIsDeterministic(): void
    {
        $generator = $this->makeGenerator();
        $order = $this->makeOrder(operatingRulesPath: null);

        $path = $generator->pathFor($order);

        self::assertStringStartsWith($this->tempDir.'/vop/vop_', $path);
        self::assertStringEndsWith('.docx', $path);
        self::assertSame($path, $generator->pathFor($order));
    }

    private function makeGenerator(): VopDocumentGenerator
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            function (string $name, array $params) {
                if ('public_place_pricelist' === $name) {
                    return 'https://test.example/pobocka/'.$params['id'].'/cenik';
                }
                if ('public_place_detail' === $name) {
                    return 'https://test.example/pobocka/'.$params['id'];
                }

                return 'https://test.example/'.$name;
            },
        );

        $context = new RequestContext(host: 'test.example', scheme: 'https');
        $urlHelper = new UrlHelper(new RequestStack(), $context);

        return new VopDocumentGenerator(
            $urlGenerator,
            $urlHelper,
            $this->tempDir.'/vop',
        );
    }

    private function makeOrder(?string $operatingRulesPath): Order
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test 1',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        if (null !== $operatingRulesPath) {
            $place->updateOperatingRules($operatingRulesPath, new \DateTimeImmutable('2025-06-15 12:00:00'));
        }

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0, 'normalized' => true],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $user = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novák',
            new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-15 12:00:00'),
            endDate: null,
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('2025-06-22 12:00:00'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }

    private function writeTemplate(string $path): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Ceník na ${PRICELIST_URL}');
        $section->addText('Provozní řád: ${OPERATING_RULES_URL}');

        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
    }

    private function readDocumentXml(string $docxPath): string
    {
        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($docxPath), 'Failed to open generated DOCX');

        try {
            $xml = $zip->getFromName('word/document.xml');
            self::assertNotFalse($xml, 'word/document.xml missing from DOCX');

            return $xml;
        } finally {
            $zip->close();
        }
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
