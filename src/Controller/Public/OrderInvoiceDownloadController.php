<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokumenty/faktura.pdf', name: 'public_order_invoice_download')]
final class OrderInvoiceDownloadController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly InvoiceRepository $invoiceRepository,
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

        $invoice = $this->invoiceRepository->findByOrder($order);

        if (null === $invoice || !$invoice->hasPdf() || null === $invoice->pdfPath) {
            throw new NotFoundHttpException('Faktura není k dispozici.');
        }

        $response = new BinaryFileResponse($invoice->pdfPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('faktura_%s.pdf', $invoice->invoiceNumber),
        );

        return $response;
    }
}
