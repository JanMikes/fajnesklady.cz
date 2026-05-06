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
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokumenty/smlouva.pdf', name: 'public_order_contract_download')]
final class OrderContractDownloadController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly DocumentPdfConverter $pdfConverter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(string $id): BinaryFileResponse
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
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

        $response = new BinaryFileResponse($pdfPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'smlouva-'.$contract->id->toBase32().'.pdf',
        );

        return $response;
    }
}
