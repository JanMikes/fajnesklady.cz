<?php

declare(strict_types=1);

namespace App\User\Command;

use App\User\Event\EmailVerified;
use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

#[AsMessageHandler]
final readonly class VerifyEmailHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(VerifyEmailCommand $command): void
    {
        // Find user
        $user = $this->userRepository->findById($command->userId);
        if (null === $user) {
            throw new \DomainException('User not found');
        }

        // Validate token using the legacy method that accepts signed URL
        $this->verifyEmailHelper->validateEmailConfirmation(
            signedUrl: $command->signedUrl,
            userId: (string) $user->getId(),
            userEmail: $user->getEmail(),
        );

        // Mark user as verified
        $user->markAsVerified();

        // Save user
        $this->userRepository->save($user);

        // Dispatch EmailVerified event
        $this->eventBus->dispatch(new EmailVerified(
            userId: $user->getId(),
        ));
    }
}
