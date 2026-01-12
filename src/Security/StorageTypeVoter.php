<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\StorageType;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, StorageType>
 */
final class StorageTypeVoter extends Voter
{
    public const VIEW = 'STORAGE_TYPE_VIEW';
    public const EDIT = 'STORAGE_TYPE_EDIT';
    public const DELETE = 'STORAGE_TYPE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof StorageType;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var StorageType $storageType */
        $storageType = $subject;

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Landlords can only access their own storage types
        if (in_array('ROLE_LANDLORD', $user->getRoles(), true)) {
            return $storageType->isOwnedBy($user);
        }

        return false;
    }
}
