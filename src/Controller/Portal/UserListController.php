<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use App\Value\UserListCriteria;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/users', name: 'portal_users_list')]
#[IsGranted('ROLE_ADMIN')]
final class UserListController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ContractRepository $contractRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now();

        $criteria = UserListCriteria::fromRequest(
            search: $request->query->get('q'),
            filter: $request->query->get('filter'),
            sort: $request->query->get('sort'),
            direction: $request->query->get('dir'),
            page: (int) $request->query->get('page', '1'),
        );

        $rows = $this->userRepository->findForAdminList($criteria, $now);
        $totalUsers = $this->userRepository->countForAdminList($criteria, $now);
        $totalPages = (int) ceil($totalUsers / $criteria->limit);

        // Filter-chip counts stay GLOBAL — independent of the active search —
        // so chips show totals while the search narrows the table (spec 066 §3).
        $activeUserIds = $this->contractRepository->findActiveContractUserIdsSubquery($now);

        return $this->render('portal/user/list.html.twig', [
            'rows' => $rows,
            'currentPage' => $criteria->page,
            'totalPages' => $totalPages,
            'totalUsers' => $totalUsers,
            'filter' => $criteria->filter,
            'search' => $criteria->search,
            'sort' => $criteria->sortColumn,
            'dir' => $criteria->sortDirection,
            'overdueUserCount' => $this->userRepository->countOverdueUsers($now),
            'onboardedUserCount' => $this->userRepository->countOnboarded(),
            'activeUserCount' => $this->userRepository->countWithActiveContracts($now, $activeUserIds),
            'inactiveUserCount' => $this->userRepository->countWithoutActiveContracts($now, $activeUserIds),
            'unverifiedUserCount' => $this->userRepository->countUnverified(),
        ]);
    }
}
