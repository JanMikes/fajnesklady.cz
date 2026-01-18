<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Repository\InvoiceRepository;
use App\Service\Security\OrderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/faktury/{id}/pdf', name: 'portal_user_invoice_pdf')]
#[IsGranted('ROLE_USER')]
final class InvoicePdfController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    public function __invoke(string $id): BinaryFileResponse
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Faktura nenalezena.');
        }

        $invoice = $this->invoiceRepository->find(Uuid::fromString($id));

        if (null === $invoice) {
            throw new NotFoundHttpException('Faktura nenalezena.');
        }

        // Use OrderVoter to check access (handles user, admin, and landlord access)
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $invoice->order);

        if (!$invoice->hasPdf() || null === $invoice->pdfPath) {
            throw new NotFoundHttpException('PDF faktury není k dispozici.');
        }

        $response = new BinaryFileResponse($invoice->pdfPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('faktura_%s.pdf', $invoice->invoiceNumber),
        );

        return $response;
    }
}
