<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Service\DocumentPdfConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokumenty/smlouva.pdf', name: 'public_order_contract_download', requirements: ['id' => '[0-9a-f-]{36}'])]
final class OrderContractDownloadController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly DocumentPdfConverter $pdfConverter,
        private readonly UriSigner $uriSigner,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(Request $request, string $id): BinaryFileResponse
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order || OrderStatus::COMPLETED !== $order->status) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $contract = $this->contractRepository->findByOrder($order);

        if (null === $contract || !$contract->hasDocument() || null === $contract->documentPath) {
            throw new NotFoundHttpException('Smlouva není k dispozici.');
        }

        $contractsDir = $this->projectDir.'/var/contracts';

        $docxPath = str_starts_with($contract->documentPath, '/')
            ? $contract->documentPath
            : $contractsDir.'/'.$contract->documentPath;

        $realPath = realpath($docxPath);

        if (false === $realPath || !str_starts_with($realPath, realpath($contractsDir).'/')) {
            throw new NotFoundHttpException('Smlouva nebyla nalezena.');
        }

        $pdfPath = $this->pdfConverter->convertToPdf($realPath);

        if (null === $pdfPath) {
            throw new NotFoundHttpException('Konverze do PDF není dostupná.');
        }

        $disposition = $request->query->getBoolean('download')
            ? HeaderUtils::DISPOSITION_ATTACHMENT
            : HeaderUtils::DISPOSITION_INLINE;

        $response = new BinaryFileResponse($pdfPath);
        $response->setContentDisposition(
            $disposition,
            'smlouva-'.$contract->id->toBase32().'.pdf',
        );

        return $response;
    }
}
