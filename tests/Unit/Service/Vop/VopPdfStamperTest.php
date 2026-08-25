<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vop;

use App\Service\DocumentPdfConverter;
use App\Service\Vop\VopPdfStamper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only the pre-stamping branches are unit-testable (the stamp itself needs
 * LibreOffice + Ghostscript). What matters here: a signed order whose PNG is
 * gone gets the plain PDF, never a failure — but never silently either.
 */
final class VopPdfStamperTest extends TestCase
{
    private string $pdfPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vop_test_');
        \assert(false !== $path);
        $this->pdfPath = $path;
        file_put_contents($this->pdfPath, '%PDF-1.7 plain-unsigned');
    }

    protected function tearDown(): void
    {
        @unlink($this->pdfPath);
    }

    public function testMissingSignatureFileLogsErrorAndServesPlainPdf(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('UNSIGNED'),
            $this->callback(static fn (array $context): bool => '/gone/signature.png' === $context['signature_path']),
        );

        $bytes = $this->buildStamper($logger)->stampSignedPdfBytes('/irrelevant/vop.docx', '/gone/signature.png');

        self::assertSame('%PDF-1.7 plain-unsigned', $bytes);
    }

    public function testNullSignatureServesPlainPdfWithoutError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $bytes = $this->buildStamper($logger)->stampSignedPdfBytes('/irrelevant/vop.docx', null);

        self::assertSame('%PDF-1.7 plain-unsigned', $bytes);
    }

    public function testFailedConversionReturnsNull(): void
    {
        $converter = $this->createStub(DocumentPdfConverter::class);
        $converter->method('convertToPdf')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $stamper = new VopPdfStamper($converter, $logger, skipLastPages: 2, signatureWidthMm: 60, signatureMarginMm: 15);

        self::assertNull($stamper->stampSignedPdfBytes('/irrelevant/vop.docx', '/gone/signature.png'));
    }

    private function buildStamper(LoggerInterface $logger): VopPdfStamper
    {
        $converter = $this->createStub(DocumentPdfConverter::class);
        $converter->method('convertToPdf')->willReturn($this->pdfPath);

        return new VopPdfStamper($converter, $logger, skipLastPages: 2, signatureWidthMm: 60, signatureMarginMm: 15);
    }
}
