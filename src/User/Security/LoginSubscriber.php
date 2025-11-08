<?php

declare(strict_types=1);

namespace App\User\Security;

use App\User\Entity\User;
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

            // Prevent the user from being authenticated
            $authenticator = $event->getAuthenticator();
            // @phpstan-ignore notIdentical.alwaysTrue
            if (null !== $authenticator) {
                $authenticator->onAuthenticationFailure(
                    $event->getRequest(),
                    new \Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException(
                        'Email not verified.'
                    )
                );
            }
        }
    }
}
