<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Command\ChangeUserRoleCommand;
use App\Admin\Form\ChangeUserRoleType;
use App\User\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_ADMIN')]
#[Route('/users', name: 'admin_users_')]
final class UserManagementController extends AbstractController
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'list')]
    public function list(): Response
    {
        $users = $this->userRepository->findAll();

        return $this->render('admin/user/list.html.twig', [
            'users' => $users,
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

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(string $id, Request $request): Response
    {
        $user = $this->userRepository->findById(Uuid::fromString($id));

        if (null === $user) {
            throw $this->createNotFoundException('User not found');
        }

        // Determine current role (skip ROLE_USER as it's default)
        $currentRole = 'ROLE_USER';
        foreach ($user->getRoles() as $role) {
            if ('ROLE_USER' !== $role) {
                $currentRole = $role;

                break;
            }
        }

        $form = $this->createForm(ChangeUserRoleType::class, ['role' => $currentRole]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $command = new ChangeUserRoleCommand(
                userId: Uuid::fromString($id),
                newRole: $data['role'],
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'User role updated successfully');

            return $this->redirectToRoute('admin_users_view', ['id' => $id]);
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }
}
