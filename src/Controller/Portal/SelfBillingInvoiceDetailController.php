<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\PaymentRepository;
use App\Repository\SelfBillingInvoiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/landlord/self-billing/{id}', name: 'portal_landlord_self_billing_detail')]
#[IsGranted('ROLE_LANDLORD')]
final class SelfBillingInvoiceDetailController extends AbstractController
{
    public function __construct(
        private readonly SelfBillingInvoiceRepository $selfBillingInvoiceRepository,
        private readonly PaymentRepository $paymentRepository,
    ) {
    }

    public function __invoke(Uuid $id): Response
    {
        /** @var User $landlord */
        $landlord = $this->getUser();

        $invoice = $this->selfBillingInvoiceRepository->get($id);

        // Ensure the invoice belongs to the current landlord
        if (!$invoice->landlord->id->equals($landlord->id)) {
            throw new AccessDeniedHttpException('K této faktuře nemáte přístup.');
        }

        $payments = $this->paymentRepository->findBySelfBillingInvoice($invoice);

        return $this->render('portal/landlord/self_billing/detail.html.twig', [
            'invoice' => $invoice,
            'payments' => $payments,
        ]);
    }
}
