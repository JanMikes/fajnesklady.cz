<?php

declare(strict_types=1);

namespace App\Service\Vop;

use App\Service\DocumentPdfConverter;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\Process\Process;

/**
 * Converts the VOP DOCX to PDF, downgrades the result to PDF 1.4 via
 * Ghostscript (FPDI free reads ≤ 1.4 only, LibreOffice emits 1.7), and
 * stamps the order's signature PNG at the bottom-left of every body
 * page. The last $skipLastPages pages stay clean — those are the form
 * annexes (withdrawal / complaint) that the customer fills in themselves.
 */
readonly class VopPdfStamper
{
    public function __construct(
        private DocumentPdfConverter $pdfConverter,
        private LoggerInterface $logger,
        private int $skipLastPages,
        private int $signatureWidthMm,
        private int $signatureMarginMm,
    ) {
    }

    public function stampSignedPdfBytes(string $docxPath, ?string $signaturePath): ?string
    {
        $pdfPath = $this->pdfConverter->convertToPdf($docxPath);
        if (null === $pdfPath) {
            return null;
        }

        if (null === $signaturePath || !file_exists($signaturePath)) {
            $bytes = file_get_contents($pdfPath);

            return false === $bytes ? null : $bytes;
        }

        $pdf14Path = $this->downgradeToPdf14($pdfPath);
        if (null === $pdf14Path) {
            return null;
        }

        // The frontend signature pad paints an opaque white canvas (see
        // assets/controllers/signature_controller.js). Without this step the
        // signature PNG would occlude body text where it overlaps the last
        // body lines on tightly-laid-out pages.
        $transparentSignaturePath = $this->makeWhiteTransparent($signaturePath);
        $stampSource = $transparentSignaturePath ?? $signaturePath;

        try {
            return $this->stampPdf($pdf14Path, $stampSource);
        } catch (\Throwable $e) {
            $this->logger->error('VOP: PDF stamping failed', ['exception' => $e]);

            return null;
        } finally {
            @unlink($pdf14Path);
            if (null !== $transparentSignaturePath) {
                @unlink($transparentSignaturePath);
            }
        }
    }

    private function downgradeToPdf14(string $pdfPath): ?string
    {
        $outPath = tempnam(sys_get_temp_dir(), 'vop_v14_').'.pdf';

        $process = new Process([
            'gs', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/default', '-dNOPAUSE', '-dQUIET', '-dBATCH',
            '-sOutputFile='.$outPath, $pdfPath,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->error('VOP: Ghostscript downgrade failed', ['exception' => $e]);

            return null;
        }

        if (!$process->isSuccessful() || !file_exists($outPath)) {
            $this->logger->error('VOP: Ghostscript downgrade non-zero exit', [
                'stderr' => $process->getErrorOutput(),
            ]);

            return null;
        }

        return $outPath;
    }

    private function stampPdf(string $pdfPath, string $signaturePath): string
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        // TCPDF would otherwise clip the signature image and silently spill
        // onto a new page when stamping near the bottom margin.
        $pdf->setAutoPageBreak(false, 0);

        $pageCount = $pdf->setSourceFile($pdfPath);
        $stampUntil = max(0, $pageCount - $this->skipLastPages);

        for ($n = 1; $n <= $pageCount; ++$n) {
            $tplId = $pdf->importPage($n);
            $size = $pdf->getTemplateSize($tplId);
            if (!is_array($size)) {
                throw new \RuntimeException(sprintf('FPDI returned no size for page %d.', $n));
            }
            $width = (float) $size['width'];
            $height = (float) $size['height'];
            /** @var string $orientation */
            $orientation = $size['orientation'] ?? ($width > $height ? 'L' : 'P');
            $pdf->AddPage($orientation, [$width, $height]);
            $pdf->useTemplate($tplId);

            if ($n <= $stampUntil) {
                $x = $this->signatureMarginMm;
                // Reserve a conservative vertical slot; PNG height is auto-scaled
                // (h: 0) so the aspect ratio is preserved regardless of signature shape.
                $y = $height - $this->signatureMarginMm - 22;
                $pdf->Image(
                    $signaturePath,
                    $x,
                    $y,
                    $this->signatureWidthMm,
                    0,
                    'PNG',
                );
            }
        }

        return $pdf->Output('', 'S');
    }

    private function makeWhiteTransparent(string $signaturePath): ?string
    {
        $src = @imagecreatefrompng($signaturePath);
        if (false === $src) {
            $this->logger->error('VOP: failed to read signature PNG', ['path' => $signaturePath]);

            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);

        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if (false === $transparent) {
            return null;
        }
        imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $rgb = imagecolorat($src, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($r >= 240 && $g >= 240 && $b >= 240) {
                    continue;
                }
                $color = imagecolorallocate($dst, $r, $g, $b);
                if (false === $color) {
                    continue;
                }
                imagesetpixel($dst, $x, $y, $color);
            }
        }

        $outPath = tempnam(sys_get_temp_dir(), 'vop_sig_').'.png';
        $ok = imagepng($dst, $outPath);

        return $ok ? $outPath : null;
    }
}
