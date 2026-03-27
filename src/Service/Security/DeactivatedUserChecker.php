<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class DeactivatedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isDeactivated()) {
            return;
        }

        $message = 'Váš účet byl deaktivován.';

        if (null !== $user->deactivationReason && '' !== $user->deactivationReason) {
            $message .= ' Důvod: '.$user->deactivationReason;
        }

        $message .= ' Pro více informací kontaktujte administrátora.';

        throw new CustomUserMessageAccountStatusException($message);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
