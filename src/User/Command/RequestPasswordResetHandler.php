<?php

declare(strict_types=1);

namespace App\User\Command;

use App\User\Event\PasswordResetRequested;
use App\User\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[AsMessageHandler]
final readonly class RequestPasswordResetHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(RequestPasswordResetCommand $command): void
    {
        // Always return successfully even if user not found (security: don't reveal which emails exist)
        $user = $this->userRepository->findByEmail($command->email);

        if (null !== $user) {
            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);

                // Dispatch event to send password reset email
                $this->eventBus->dispatch(
                    new PasswordResetRequested(
                        userId: $user->getId(),
                        email: $user->getEmail(),
                        resetToken: $resetToken->getToken(),
                        occurredOn: new \DateTimeImmutable(),
                    )
                );
            } catch (ResetPasswordExceptionInterface) {
                // Don't reveal that there was an error - continue execution
            }
        } else {
            // Prevent timing attacks: simulate the same amount of work when user doesn't exist
            // Random delay between 50-150ms to match approximate time of token generation
            usleep(random_int(50000, 150000));
        }

        // Always return without revealing whether user exists or not
    }
}
