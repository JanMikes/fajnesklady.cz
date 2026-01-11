<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\User\Command\RegisterUserCommand;
use App\User\Form\RegistrationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactoryInterface $registrationLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
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
}
