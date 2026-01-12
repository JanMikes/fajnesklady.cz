<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Storage;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Storage>
 */
final class StorageVoter extends Voter
{
    public const string VIEW = 'STORAGE_VIEW';
    public const string EDIT = 'STORAGE_EDIT';
    public const string DELETE = 'STORAGE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Storage;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Storage $storage */
        $storage = $subject;

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Landlords can only access their own storages
        if (in_array('ROLE_LANDLORD', $user->getRoles(), true)) {
            return $storage->isOwnedBy($user);
        }

        return false;
    }
}
