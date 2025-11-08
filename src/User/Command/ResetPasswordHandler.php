<?php

declare(strict_types=1);

namespace App\User\Command;

use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[AsMessageHandler]
final readonly class ResetPasswordHandler
{
    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @throws ResetPasswordExceptionInterface
     */
    public function __invoke(ResetPasswordCommand $command): void
    {
        // Validate the token and get the user
        $user = $this->resetPasswordHelper->validateTokenAndFetchUser($command->token);

        if (!$user instanceof \App\User\Entity\User) {
            throw new \DomainException('Invalid user type');
        }

        // Hash the new password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $command->newPassword);

        // Update the user's password
        $user->setPassword($hashedPassword);

        // Save the user
        $this->userRepository->save($user);

        // Remove the reset password request
        $this->resetPasswordHelper->removeResetRequest($command->token);
    }
}
