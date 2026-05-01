<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\SetUserPasswordCommand;
use App\Entity\User;
use App\Form\AdminUserPasswordFormData;
use App\Form\AdminUserPasswordFormType;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/users/{id}/change-password', name: 'portal_users_change_password')]
#[IsGranted('ROLE_ADMIN')]
final class UserChangePasswordController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id, #[CurrentUser] User $currentUser): Response
    {
        $user = $this->userRepository->get(Uuid::fromString($id));

        if ($user->id->equals($currentUser->id)) {
            throw $this->createAccessDeniedException('Pro změnu vlastního hesla použijte profil.');
        }

        $formData = new AdminUserPasswordFormData();
        $form = $this->createForm(AdminUserPasswordFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->commandBus->dispatch(new SetUserPasswordCommand(
                userId: $user->id,
                plainPassword: $formData->newPassword,
            ));

            $this->addFlash('success', 'Heslo uživatele bylo změněno.');

            return $this->redirectToRoute('portal_users_view', ['id' => $id]);
        }

        return $this->render('portal/user/change_password.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }
}
