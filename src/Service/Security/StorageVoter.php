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
    public const string EDIT_PRICES = 'STORAGE_EDIT_PRICES';
    public const string MANAGE_PHOTOS = 'STORAGE_MANAGE_PHOTOS';
    public const string ASSIGN_OWNER = 'STORAGE_ASSIGN_OWNER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW,
            self::EDIT,
            self::DELETE,
            self::EDIT_PRICES,
            self::MANAGE_PHOTOS,
            self::ASSIGN_OWNER,
        ], true)
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

        // Landlords can only manage storages they own
        if (in_array('ROLE_LANDLORD', $user->getRoles(), true)) {
            // Must be the owner for any access
            if (!$storage->isOwnedBy($user)) {
                return false;
            }

            return match ($attribute) {
                // Owner can view, edit prices, and manage photos
                self::VIEW, self::EDIT_PRICES, self::MANAGE_PHOTOS, self::EDIT => true,
                // Only admin can delete or assign owner
                self::DELETE, self::ASSIGN_OWNER => false,
                default => false,
            };
        }

        return false;
    }
}
