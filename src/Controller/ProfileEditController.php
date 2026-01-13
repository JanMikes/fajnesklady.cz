<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\UpdateProfileCommand;
use App\Entity\User;
use App\Form\ProfileFormData;
use App\Form\ProfileFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile/edit', name: 'app_profile_edit')]
#[IsGranted('ROLE_USER')]
final class ProfileEditController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $formData = ProfileFormData::fromUser($user);
        $form = $this->createForm(ProfileFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->commandBus->dispatch(new UpdateProfileCommand(
                userId: $user->id,
                firstName: $formData->firstName,
                lastName: $formData->lastName,
                phone: $formData->phone,
            ));

            $this->addFlash('success', 'Profil byl úspěšně aktualizován.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('user/profile_edit.html.twig', [
            'form' => $form,
        ]);
    }
}
