<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\BankTransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/bankovni-platby', name: 'admin_bank_payments')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBankPaymentsController extends AbstractController
{
    public function __construct(
        private readonly BankTransactionRepository $bankTransactionRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $filter = $request->query->getString('filter', 'all');
        $transactions = $this->bankTransactionRepository->findAll($filter);
        $unmatchedCount = $this->bankTransactionRepository->countUnmatched();

        return $this->render('admin/bank_payments/index.html.twig', [
            'transactions' => $transactions,
            'filter' => $filter,
            'unmatchedCount' => $unmatchedCount,
            'ignoredCount' => $this->bankTransactionRepository->countIgnored(),
        ]);
    }
}
