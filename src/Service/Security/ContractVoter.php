<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Contract;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Contract>
 */
final class ContractVoter extends Voter
{
    public const string VIEW = 'CONTRACT_VIEW';
    public const string DOWNLOAD = 'CONTRACT_DOWNLOAD';
    public const string TERMINATE = 'CONTRACT_TERMINATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::DOWNLOAD, self::TERMINATE], true)
            && $subject instanceof Contract;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Contract $contract */
        $contract = $subject;

        // Admins can do anything
        if ($user->isAdmin()) {
            return true;
        }

        // Users can view/download their own contracts
        if ($contract->user->id->equals($user->id)) {
            if (self::TERMINATE === $attribute) {
                return $contract->billingMode->isRecurring() && !$contract->isTerminated() && !$contract->hasPendingTermination();
            }

            return true;
        }

        // Landlords can view/download contracts for their storages
        if ($user->isLandlord()) {
            $isOwned = $contract->storage->isOwnedBy($user);

            // Landlords cannot terminate user contracts (only users can)
            if (self::TERMINATE === $attribute) {
                return false;
            }

            return $isOwned;
        }

        return false;
    }
}
