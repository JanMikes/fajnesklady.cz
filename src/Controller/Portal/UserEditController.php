<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Form\UserRoleFormData;
use App\Form\UserRoleFormType;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/users/{id}/edit', name: 'portal_users_edit')]
#[IsGranted('ROLE_ADMIN')]
final class UserEditController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $user = $this->userRepository->get(Uuid::fromString($id));

        $formData = UserRoleFormData::fromUser($user);
        $form = $this->createForm(UserRoleFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->changeRole($formData->role, new \DateTimeImmutable());
            $this->userRepository->save($user);

            $this->addFlash('success', 'User role has been updated.');

            return $this->redirectToRoute('portal_users_view', ['id' => $id]);
        }

        return $this->render('portal/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }
}
