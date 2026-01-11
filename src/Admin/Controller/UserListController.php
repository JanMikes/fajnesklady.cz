<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\User\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/users', name: 'admin_users_list')]
#[IsGranted('ROLE_ADMIN')]
final class UserListController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;

        $users = $this->userRepository->findAllPaginated($page, $limit);
        $totalUsers = $this->userRepository->countTotal();
        $totalPages = (int) ceil($totalUsers / $limit);

        return $this->render('admin/user/list.html.twig', [
            'users' => $users,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalUsers' => $totalUsers,
        ]);
    }
}
