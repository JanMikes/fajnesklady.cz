<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\HandoverProtocol;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, HandoverProtocol>
 */
final class HandoverProtocolVoter extends Voter
{
    public const string VIEW = 'HANDOVER_VIEW';
    public const string COMPLETE_TENANT = 'HANDOVER_COMPLETE_TENANT';
    public const string COMPLETE_LANDLORD = 'HANDOVER_COMPLETE_LANDLORD';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::COMPLETE_TENANT, self::COMPLETE_LANDLORD], true)
            && $subject instanceof HandoverProtocol;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var HandoverProtocol $protocol */
        $protocol = $subject;
        $contract = $protocol->contract;

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($user, $contract),
            self::COMPLETE_TENANT => $this->canCompleteTenant($user, $protocol),
            self::COMPLETE_LANDLORD => $this->canCompleteLandlord($user, $protocol),
            default => false,
        };
    }

    private function canView(User $user, \App\Entity\Contract $contract): bool
    {
        // Tenant can view
        if ($contract->user->id->equals($user->id)) {
            return true;
        }

        // Landlord can view
        if ($contract->storage->isOwnedBy($user)) {
            return true;
        }

        return false;
    }

    private function canCompleteTenant(User $user, HandoverProtocol $protocol): bool
    {
        return $protocol->contract->user->id->equals($user->id)
            && $protocol->needsTenantCompletion();
    }

    private function canCompleteLandlord(User $user, HandoverProtocol $protocol): bool
    {
        return $protocol->contract->storage->isOwnedBy($user)
            && $protocol->needsLandlordCompletion();
    }
}
