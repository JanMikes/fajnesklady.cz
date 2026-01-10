<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\User\Command\RequestPasswordResetCommand;
use App\User\Command\ResetPasswordCommand;
use App\User\Form\RequestPasswordResetType;
use App\User\Form\ResetPasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;

final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'limiter.password_reset')]
        private readonly RateLimiterFactoryInterface $passwordResetLimiter,
    ) {
    }

    #[Route('/reset-password/request', name: 'app_request_password_reset')]
    public function request(Request $request): Response
    {
        $command = new RequestPasswordResetCommand(email: '');
        $form = $this->createForm(RequestPasswordResetType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check rate limit
            $limiter = $this->passwordResetLimiter->create($request->getClientIp() ?? 'unknown');
            if (false === $limiter->consume(1)->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Too many password reset attempts. Please try again later.');
            }
            $this->commandBus->dispatch($command);

            // Always show success message (security: don't reveal if email exists)
            $this->addFlash('success', 'If an account exists with this email, you will receive a password reset link.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('user/reset_password/request.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'app_reset_password')]
    public function reset(Request $request, string $token): Response
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
