<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\User\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_ADMIN')]
#[Route('/users', name: 'admin_users_')]
final class UserManagementController extends AbstractController
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    #[Route('', name: 'list')]
    public function list(Request $request): Response
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

    #[Route('/{id}', name: 'view')]
    public function view(string $id): Response
    {
        $user = $this->userRepository->findById(Uuid::fromString($id));

        if (null === $user) {
            throw $this->createNotFoundException('User not found');
        }

        return $this->render('admin/user/view.html.twig', [
            'user' => $user,
        ]);
    }
}
