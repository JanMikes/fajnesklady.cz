<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\StorageType;
use App\Entity\User;
use App\Repository\PlaceRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, StorageType>
 */
final class StorageTypeVoter extends Voter
{
    public const string VIEW = 'STORAGE_TYPE_VIEW';
    public const string EDIT = 'STORAGE_TYPE_EDIT';
    public const string DELETE = 'STORAGE_TYPE_DELETE';

    public function __construct(
        private readonly PlaceRepository $placeRepository,
    ) {
    }

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

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Landlords can view all, and edit/delete at places they own storages at
        if (in_array('ROLE_LANDLORD', $user->getRoles(), true)) {
            if (self::VIEW === $attribute) {
                return true;
            }

            return $this->placeRepository->isOwnedBy($subject->place, $user);
        }

        return false;
    }
}
