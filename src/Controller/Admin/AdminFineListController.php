<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\FineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/pokuty', name: 'admin_fine_list')]
#[IsGranted('ROLE_ADMIN')]
final class AdminFineListController extends AbstractController
{
    private const array VALID_STATUSES = ['unpaid', 'paid', 'cancelled'];

    public function __construct(
        private readonly FineRepository $fineRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $statusParam = $request->query->get('status');
        $status = is_string($statusParam) && in_array($statusParam, self::VALID_STATUSES, true) ? $statusParam : null;
        $search = $request->query->getString('search');
        $search = '' !== $search ? $search : null;

        $paginator = $this->fineRepository->findAllFiltered($status, $search, $page);
        $total = count($paginator);
        $totalPages = (int) ceil($total / 20);

        return $this->render('admin/fine/list.html.twig', [
            'fines' => $paginator,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalFines' => $total,
            'status' => $status,
            'search' => $search,
        ]);
    }
}
