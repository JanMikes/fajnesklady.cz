<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Place;
use App\Entity\User;
use App\Repository\PlaceAccessRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Place>
 */
final class PlaceVoter extends Voter
{
    public const string VIEW = 'PLACE_VIEW';
    public const string EDIT = 'PLACE_EDIT';
    public const string DELETE = 'PLACE_DELETE';
    public const string REQUEST_CHANGE = 'PLACE_REQUEST_CHANGE';

    public function __construct(
        private readonly PlaceAccessRepository $placeAccessRepository,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::REQUEST_CHANGE], true)
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

        // Landlords
        if (in_array('ROLE_LANDLORD', $user->getRoles(), true)) {
            return match ($attribute) {
                // All landlords can view all places
                self::VIEW => true,
                // Only landlords with access can request changes
                self::REQUEST_CHANGE => $this->placeAccessRepository->hasAccess($user, $place),
                // Landlords cannot edit or delete places
                self::EDIT, self::DELETE => false,
                default => false,
            };
        }

        return false;
    }
}
