<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\SelfBillingInvoiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/landlord/self-billing', name: 'portal_landlord_self_billing_list')]
#[IsGranted('ROLE_LANDLORD')]
final class SelfBillingInvoiceListController extends AbstractController
{
    public function __construct(
        private readonly SelfBillingInvoiceRepository $selfBillingInvoiceRepository,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $landlord */
        $landlord = $this->getUser();

        $invoices = $this->selfBillingInvoiceRepository->findByLandlord($landlord);

        return $this->render('portal/landlord/self_billing/list.html.twig', [
            'invoices' => $invoices,
        ]);
    }
}
