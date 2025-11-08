<?php

declare(strict_types=1);

namespace App\User\Command;

use App\User\Event\PasswordResetRequested;
use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[AsMessageHandler]
final readonly class RequestPasswordResetHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(RequestPasswordResetCommand $command): void
    {
        // Always return successfully even if user not found (security: don't reveal which emails exist)
        $user = $this->userRepository->findByEmail($command->email);

        if (null === $user) {
            // Don't reveal that the user doesn't exist
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Don't reveal that there was an error
            return;
        }

        // Dispatch event to send password reset email
        $this->eventBus->dispatch(
            new PasswordResetRequested(
                userId: $user->getId(),
                email: $user->getEmail(),
                resetToken: $resetToken->getToken(),
                occurredOn: new \DateTimeImmutable(),
            )
        );
    }
}
