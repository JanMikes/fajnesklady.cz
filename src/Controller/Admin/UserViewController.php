<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/users/{id}', name: 'admin_users_view')]
#[IsGranted('ROLE_ADMIN')]
final class UserViewController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function __invoke(string $id): Response
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
