<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Overdue\OverdueChecker;
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
        private readonly OverdueChecker $overdueChecker,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;
        $filterParam = $request->query->get('filter');
        $filter = match ($filterParam) {
            'overdue', 'onboarded' => $filterParam,
            default => null,
        };
        $now = $this->clock->now();

        switch ($filter) {
            case 'overdue':
                $users = $this->userRepository->findOverduePaginated($page, $limit, $now);
                $totalUsers = $this->userRepository->countOverdueUsers($now);

                break;
            case 'onboarded':
                $users = $this->userRepository->findOnboardedPaginated($page, $limit);
                $totalUsers = $this->userRepository->countOnboarded();

                break;
            default:
                $users = $this->userRepository->findAllPaginated($page, $limit);
                $totalUsers = $this->userRepository->countTotal();
        }

        $totalPages = (int) ceil($totalUsers / $limit);
        $overdueUserCount = $this->userRepository->countOverdueUsers($now);
        $onboardedUserCount = $this->userRepository->countOnboarded();

        $pageUserIds = array_map(static fn (User $u) => $u->id, $users);
        $debtorIdSet = array_flip($this->overdueChecker->filterOverdueUserIds($now, $pageUserIds));
        $onboardedIdSet = array_flip($this->userRepository->findOnboardedUserIds($pageUserIds));

        return $this->render('portal/user/list.html.twig', [
            'users' => $users,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalUsers' => $totalUsers,
            'filter' => $filter,
            'overdueUserCount' => $overdueUserCount,
            'onboardedUserCount' => $onboardedUserCount,
            'debtorIdSet' => $debtorIdSet,
            'onboardedIdSet' => $onboardedIdSet,
        ]);
    }
}
