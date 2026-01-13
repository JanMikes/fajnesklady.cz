<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\ChangePasswordCommand;
use App\Entity\User;
use App\Exception\InvalidCurrentPassword;
use App\Form\ChangePasswordFormData;
use App\Form\ChangePasswordFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile/change-password', name: 'app_profile_change_password')]
#[IsGranted('ROLE_USER')]
final class ChangePasswordController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $formData = new ChangePasswordFormData();
        $form = $this->createForm(ChangePasswordFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commandBus->dispatch(new ChangePasswordCommand(
                    userId: $user->id,
                    currentPassword: $formData->currentPassword,
                    newPassword: $formData->newPassword,
                ));

                $this->addFlash('success', 'Heslo bylo úspěšně změněno.');

                return $this->redirectToRoute('app_profile');
            } catch (InvalidCurrentPassword) {
                $this->addFlash('error', 'Aktuální heslo není správné.');
            }
        }

        return $this->render('user/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
