<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Verifies a re-entered account password for high-impact ("Nebezpečná zóna")
 * destructive actions. Used at the controller boundary, next to voter checks.
 */
final readonly class PasswordConfirmation
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function isValid(User $user, ?string $plainPassword): bool
    {
        if (null === $plainPassword || '' === $plainPassword || !$user->hasPassword()) {
            return false;
        }

        return $this->passwordHasher->isPasswordValid($user, $plainPassword);
    }
}
