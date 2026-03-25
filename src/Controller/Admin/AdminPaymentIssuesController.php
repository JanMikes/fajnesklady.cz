<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ContractRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/payment-issues', name: 'admin_payment_issues')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPaymentIssuesController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        $now = $this->clock->now();
        $contracts = $this->contractRepository->findWithPaymentIssues($now);
        $totalDebt = $this->contractRepository->sumOutstandingDebt();
        $debtCount = $this->contractRepository->countWithOutstandingDebt();

        return $this->render('admin/payment_issues.html.twig', [
            'contracts' => $contracts,
            'totalDebt' => $totalDebt,
            'debtCount' => $debtCount,
        ]);
    }
}
