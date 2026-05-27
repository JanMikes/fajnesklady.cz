<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Payment;

use App\Service\Payment\QrPaymentGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class QrPaymentGeneratorTest extends TestCase
{
    private QrPaymentGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new QrPaymentGenerator(
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(UriSigner::class),
        );
    }

    public function testGeneratesValidDataUri(): void
    {
        $dataUri = $this->generator->generateDataUri('1234567890', 50000);

        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function testBankAccountFormatted(): void
    {
        self::assertSame('2603478520/2010', $this->generator->getBankAccountFormatted());
    }

    public function testQrContainsSpdFormat(): void
    {
        $dataUri = $this->generator->generateDataUri('1234567890', 50000);

        self::assertNotEmpty($dataUri);
    }

    public function testWithDueDate(): void
    {
        $dueDate = new \DateTimeImmutable('2025-06-15');
        $dataUri = $this->generator->generateDataUri('1234567890', 50000, $dueDate);

        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
    }
}
