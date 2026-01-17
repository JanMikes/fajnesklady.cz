<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\SelfBillingInvoiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/landlord/self-billing/{id}/pdf', name: 'portal_landlord_self_billing_pdf')]
#[IsGranted('ROLE_LANDLORD')]
final class SelfBillingInvoicePdfController extends AbstractController
{
    public function __construct(
        private readonly SelfBillingInvoiceRepository $selfBillingInvoiceRepository,
    ) {
    }

    public function __invoke(Uuid $id): BinaryFileResponse
    {
        /** @var User $landlord */
        $landlord = $this->getUser();

        $invoice = $this->selfBillingInvoiceRepository->get($id);

        // Ensure the invoice belongs to the current landlord
        if (!$invoice->landlord->id->equals($landlord->id)) {
            throw new AccessDeniedHttpException('You do not have access to this invoice.');
        }

        if (!$invoice->hasPdf() || null === $invoice->pdfPath) {
            throw new NotFoundHttpException('PDF not available for this invoice.');
        }

        $response = new BinaryFileResponse($invoice->pdfPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('self_billing_%s.pdf', $invoice->invoiceNumber),
        );

        return $response;
    }
}
