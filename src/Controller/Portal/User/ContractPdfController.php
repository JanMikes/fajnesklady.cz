<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Repository\ContractRepository;
use App\Service\DocumentPdfConverter;
use App\Service\Security\ContractVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/smlouvy/{id}/pdf', name: 'portal_user_contract_pdf')]
#[IsGranted('ROLE_USER')]
final class ContractPdfController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly DocumentPdfConverter $pdfConverter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(string $id): BinaryFileResponse
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        try {
            $contract = $this->contractRepository->get(Uuid::fromString($id));
        } catch (\Exception) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        $this->denyAccessUnlessGranted(ContractVoter::DOWNLOAD, $contract);

        if (!$contract->hasDocument()) {
            throw new NotFoundHttpException('Dokument smlouvy není k dispozici.');
        }

        $contractsDir = $this->projectDir.'/var/contracts';
        $docxPath = $contractsDir.'/'.$contract->documentPath;
        $realPath = realpath($docxPath);

        if (false === $realPath || !str_starts_with($realPath, realpath($contractsDir).'/')) {
            throw new NotFoundHttpException('Dokument smlouvy nebyl nalezen.');
        }

        $pdfPath = $this->pdfConverter->convertToPdf($realPath);

        if (null === $pdfPath) {
            throw new NotFoundHttpException('Konverze do PDF není dostupná. Stáhněte prosím DOCX verzi.');
        }

        $response = new BinaryFileResponse($pdfPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'smlouva-'.$contract->id->toBase32().'.pdf'
        );

        return $response;
    }
}
