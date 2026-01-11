<?php

declare(strict_types=1);

namespace App\User\Controller;

use App\User\Command\VerifyEmailCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

#[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
final class VerifyEmailController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
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
}
