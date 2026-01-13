<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ContractRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/contracts', name: 'admin_contracts_list')]
#[IsGranted('ROLE_ADMIN')]
final class AdminContractListController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;

        $contracts = $this->contractRepository->findAllPaginated($page, $limit);
        $totalContracts = $this->contractRepository->countTotal();
        $totalPages = (int) ceil($totalContracts / $limit);

        return $this->render('admin/contract/list.html.twig', [
            'contracts' => $contracts,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalContracts' => $totalContracts,
        ]);
    }
}
