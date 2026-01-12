<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Place;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Place>
 */
final class PlaceVoter extends Voter
{
    public const VIEW = 'PLACE_VIEW';
    public const EDIT = 'PLACE_EDIT';
    public const DELETE = 'PLACE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Place;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Place $place */
        $place = $subject;

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Landlords can only access their own places
        if (in_array('ROLE_LANDLORD', $user->getRoles(), true)) {
            return $place->isOwnedBy($user);
        }

        return false;
    }
}
