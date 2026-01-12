<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Order;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Order>
 */
final class OrderVoter extends Voter
{
    public const string VIEW = 'ORDER_VIEW';
    public const string CANCEL = 'ORDER_CANCEL';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::CANCEL], true)
            && $subject instanceof Order;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Order $order */
        $order = $subject;

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Users can view/cancel their own orders
        if ($order->user->id->equals($user->id)) {
            return true;
        }

        // Landlords can view orders for their storages
        if (in_array('ROLE_LANDLORD', $user->getRoles(), true)) {
            return $order->storage->isOwnedBy($user);
        }

        return false;
    }
}
