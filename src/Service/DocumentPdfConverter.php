<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

final readonly class DocumentPdfConverter
{
    public function __construct(
        private string $cacheDirectory,
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
        } catch (\Exception) {
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
        } catch (\Exception) {
            return false;
        }
    }
}
