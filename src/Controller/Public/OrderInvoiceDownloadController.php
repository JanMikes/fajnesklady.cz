<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/objednavka/{id}/dokumenty/faktura/{invoiceId}.pdf',
    name: 'public_order_invoice_download',
    requirements: [
        'id' => '[0-9a-f-]{36}',
        'invoiceId' => '[0-9a-f-]{36}',
    ],
)]
final class OrderInvoiceDownloadController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly UriSigner $uriSigner,
    ) {
    }

    public function __invoke(Request $request, string $id, string $invoiceId): BinaryFileResponse
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order || OrderStatus::COMPLETED !== $order->status) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $invoice = $this->invoiceRepository->find(Uuid::fromString($invoiceId));

        if (null === $invoice || !$invoice->order->id->equals($order->id)) {
            throw new NotFoundHttpException('Faktura nenalezena.');
        }

        if (!$invoice->hasPdf() || null === $invoice->pdfPath) {
            throw new NotFoundHttpException('Faktura není k dispozici.');
        }

        $disposition = $request->query->getBoolean('download')
            ? HeaderUtils::DISPOSITION_ATTACHMENT
            : HeaderUtils::DISPOSITION_INLINE;

        $response = new BinaryFileResponse($invoice->pdfPath);
        $response->setContentDisposition(
            $disposition,
            sprintf('faktura_%s.pdf', $invoice->invoiceNumber),
        );

        return $response;
    }
}
