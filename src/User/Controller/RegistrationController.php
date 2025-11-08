<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\User\Command\RegisterUserCommand;
use App\User\Command\VerifyEmailCommand;
use App\User\Form\RegistrationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly RateLimiterFactory $registrationLimiter,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $form = $this->createForm(RegistrationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check rate limit
            $limiter = $this->registrationLimiter->create($request->getClientIp() ?? 'unknown');
            if (false === $limiter->consume(1)->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Too many registration attempts. Please try again later.');
            }
            $data = $form->getData();

            try {
                // Create and dispatch RegisterUserCommand
                $command = new RegisterUserCommand(
                    email: $data['email'],
                    password: $data['password'],
                    name: $data['name'],
                );

                $this->commandBus->dispatch($command);

                // Add flash message and redirect
                $this->addFlash('success', 'Registration successful! Please check your email to verify your account.');

                return $this->redirectToRoute('app_verify_email_confirmation');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (\Exception $e) {
                $this->addFlash('error', 'An error occurred during registration. Please try again.');
            }
        }

        return $this->render('user/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $userId = $request->query->get('id');
        $token = $request->query->get('token');

        if (!$userId || !$token) {
            $this->addFlash('error', 'Invalid verification link.');

            return $this->redirectToRoute('app_login');
        }

        try {
            // Create and dispatch VerifyEmailCommand
            $command = new VerifyEmailCommand(
                userId: Uuid::fromString($userId),
                signedUrl: $request->getUri(),
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Your email has been verified! You can now log in.');

            return $this->redirectToRoute('app_login');
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('error', $e->getReason());

            return $this->redirectToRoute('app_register');
        } catch (\Exception $e) {
            $this->addFlash('error', 'An error occurred during email verification. Please try again.');

            return $this->redirectToRoute('app_register');
        }
    }

    #[Route('/verify-email/confirmation', name: 'app_verify_email_confirmation', methods: ['GET'])]
    public function confirmation(): Response
    {
        return $this->render('user/verify_email_confirmation.html.twig');
    }
}
