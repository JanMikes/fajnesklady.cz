<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\User\Command\ResetPasswordCommand;
use App\User\Form\ResetPasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;

#[Route('/reset-password/reset/{token}', name: 'app_reset_password')]
final class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();

            $command = new ResetPasswordCommand(
                token: $token,
                newPassword: $newPassword,
            );

            try {
                $this->commandBus->dispatch($command);

                $this->addFlash('success', 'Your password has been successfully reset. You can now log in with your new password.');

                return $this->redirectToRoute('app_login');
            } catch (ResetPasswordExceptionInterface $e) {
                $this->addFlash('error', 'There was a problem validating your reset request. The link may have expired or is invalid.');

                return $this->redirectToRoute('app_request_password_reset');
            }
        }

        return $this->render('user/reset_password/reset.html.twig', [
            'form' => $form,
            'token' => $token,
        ]);
    }
}
