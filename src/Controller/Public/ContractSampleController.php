<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Service\DocumentPdfConverter;
use PhpOffice\PhpWord\TemplateProcessor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/vzor-smlouvy', name: 'public_contract_sample')]
final class ContractSampleController extends AbstractController
{
    public function __construct(
        private readonly DocumentPdfConverter $pdfConverter,
        #[Autowire('%kernel.project_dir%/templates/documents/contract_template.docx')]
        private readonly string $contractTemplatePath,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
    ) {
    }

    public function __invoke(): Response
    {
        $pdfBytes = $this->getCachedPdf();

        if (null === $pdfBytes) {
            throw new NotFoundHttpException('Vzor smlouvy není momentálně dostupný.');
        }

        return new Response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vzor-smlouvy.pdf"',
        ]);
    }

    private function getCachedPdf(): ?string
    {
        $cachePath = $this->cacheDir.'/contract_sample.pdf';
        $templateMtime = @filemtime($this->contractTemplatePath);

        if (file_exists($cachePath) && @filemtime($cachePath) >= $templateMtime) {
            $bytes = file_get_contents($cachePath);

            return false === $bytes ? null : $bytes;
        }

        $docxBytes = $this->renderSampleDocx();

        if (null === $docxBytes) {
            return null;
        }

        $pdfBytes = $this->pdfConverter->convertBytesToPdfBytes($docxBytes);

        if (null === $pdfBytes) {
            return null;
        }

        file_put_contents($cachePath, $pdfBytes);

        return $pdfBytes;
    }

    private function renderSampleDocx(): ?string
    {
        if (!file_exists($this->contractTemplatePath)) {
            return null;
        }

        $processor = new TemplateProcessor($this->contractTemplatePath);

        $processor->setValue('TENANT_INFO', "Jméno: _______________\nNar. _______________\nBytem: _______________\nEmail: _______________\nTelefon: _______________");
        $processor->setValue('STORAGE_DESCRIPTION', '_______________ č. ___ (___ × ___ × ___ cm)');
        $processor->setValue('CONTRACT_NUMBER', '____-____-________');
        $processor->setValue('RENTAL_DURATION_TEXT', 'Nájem se sjednává na dobu určitou, a to od ___.___.___ do ___.___.___');
        $processor->setValue('CONTRACT_CITY', '_______________');
        $processor->setValue('CONTRACT_DATE', '___.___.___');
        $processor->setValue('SIGNING_PLACE', '_______________');
        $processor->setValue('SIGNING_DATE', '___.___.___');
        $processor->setValue('SIGNATURE', '');

        $tempPath = tempnam(sys_get_temp_dir(), 'contract_sample_');

        if (false === $tempPath) {
            return null;
        }

        try {
            $processor->saveAs($tempPath);
            $bytes = file_get_contents($tempPath);

            return false === $bytes ? null : $bytes;
        } finally {
            @unlink($tempPath);
        }
    }
}
