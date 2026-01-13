<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\ResetPasswordCommand;
use App\Form\ResetPasswordFormData;
use App\Form\ResetPasswordFormType;
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
        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ResetPasswordFormData $formData */
            $formData = $form->getData();

            $command = new ResetPasswordCommand(
                token: $token,
                newPassword: $formData->newPassword,
            );

            try {
                $this->commandBus->dispatch($command);

                $this->addFlash('success', 'Vaše heslo bylo úspěšně obnoveno. Nyní se můžete přihlásit s novým heslem.');

                return $this->redirectToRoute('app_login');
            } catch (ResetPasswordExceptionInterface $e) {
                $this->addFlash('error', 'Při ověřování požadavku na obnovení hesla došlo k chybě. Odkaz mohl vypršet nebo je neplatný.');

                return $this->redirectToRoute('app_request_password_reset');
            }
        }

        return $this->render('user/reset_password/reset.html.twig', [
            'form' => $form,
            'token' => $token,
        ]);
    }
}
