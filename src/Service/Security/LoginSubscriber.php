<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
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

        if (!$user->isVerified()) {
            // Check if user is a landlord awaiting admin verification
            if (in_array(UserRole::LANDLORD->value, $user->getRoles(), true)) {
                $event->setResponse(
                    new RedirectResponse($this->urlGenerator->generate('app_landlord_awaiting_verification'))
                );

                return;
            }

            // Regular user needs email verification
            $request = $this->requestStack->getCurrentRequest();
            if (null !== $request && $request->hasSession()) {
                $session = $request->getSession();
                $session->getFlashBag()->add(
                    'error',
                    'Please verify your email address before logging in. Check your inbox for the verification link.'
                );
            }

            $event->setResponse(
                new RedirectResponse($this->urlGenerator->generate('app_login'))
            );
        }
    }
}
