<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

readonly class DocumentPdfConverter
{
    public function __construct(
        private string $cacheDirectory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Convert a DOCX file to PDF using LibreOffice.
     *
     * @param string $docxPath Path to the DOCX file
     *
     * @return string|null Path to the generated PDF, or null if conversion failed
     */
    public function convertToPdf(string $docxPath): ?string
    {
        if (!file_exists($docxPath)) {
            return null;
        }

        $outputDir = $this->cacheDirectory.'/pdf_conversions';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $process = new Process([
            'soffice',
            '--headless',
            '--convert-to', 'pdf',
            '--outdir', $outputDir,
            $docxPath,
        ]);

        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Exception $e) {
            $this->logger->error('PDF conversion failed', [
                'docx_path' => $docxPath,
                'exception' => $e,
            ]);

            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $pdfPath = $outputDir.'/'.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

        if (!file_exists($pdfPath)) {
            return null;
        }

        return $pdfPath;
    }

    public function isAvailable(): bool
    {
        $process = new Process(['which', 'soffice']);

        try {
            $process->run();

            return $process->isSuccessful() && '' !== trim($process->getOutput());
        } catch (\Exception $e) {
            $this->logger->warning('LibreOffice availability check failed', [
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Convert DOCX bytes to PDF bytes via a temporary file.
     */
    public function convertBytesToPdfBytes(string $docxBytes): ?string
    {
        // LibreOffice derives the output filename from the input, so the
        // temp file must end with .docx — tempnam alone produces no extension.
        $tempBase = tempnam(sys_get_temp_dir(), 'pdf_src_');
        if (false === $tempBase) {
            return null;
        }
        $docxPath = $tempBase.'.docx';

        if (false === file_put_contents($docxPath, $docxBytes)) {
            @unlink($tempBase);

            return null;
        }

        try {
            $pdfPath = $this->convertToPdf($docxPath);
            if (null === $pdfPath) {
                return null;
            }

            $pdfBytes = file_get_contents($pdfPath);
            @unlink($pdfPath);

            return false === $pdfBytes ? null : $pdfBytes;
        } finally {
            @unlink($docxPath);
            @unlink($tempBase);
        }
    }
}
