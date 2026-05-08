<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Stamps User::$lastLoginAt on every successful login.
 *
 * Security events fire OUTSIDE the messenger doctrine_transaction middleware,
 * so this subscriber flushes directly via EntityManagerInterface — one of the
 * documented exceptions to the "no flush in services" rule.
 */
final class RecordLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $user->recordLogin($this->clock->now());

        // Manual flush() required: Symfony security events fire from the HTTP
        // request lifecycle, which has no messenger doctrine_transaction
        // middleware around it. There is no other code path that will commit
        // this change, so the subscriber owns the flush itself.
        $this->entityManager->flush();
    }
}
