<?php

declare(strict_types=1);

namespace App\User\Security;

use App\User\Entity\User;
use App\User\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly UserRepository $userRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Check if account is locked
        if ($user->isLocked()) {
            $request = $this->requestStack->getCurrentRequest();
            if (null !== $request && $request->hasSession()) {
                $lockedUntil = $user->getLockedUntil();
                $message = 'Your account has been temporarily locked due to too many failed login attempts.';
                if (null !== $lockedUntil) {
                    $message .= sprintf(' Please try again after %s.', $lockedUntil->format('Y-m-d H:i:s'));
                }

                $request->getSession()->getFlashBag()->add('error', $message);
            }

            $event->setResponse(
                new RedirectResponse($this->urlGenerator->generate('app_login'))
            );

            return;
        }

        // Check if email is verified
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

            return;
        }

        // Reset failed login attempts on successful login
        $user->resetFailedLoginAttempts();
        $this->userRepository->save($user);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $passport = $event->getPassport();

        if (null === $passport) {
            return;
        }

        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);

        if (!$userBadge instanceof \Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge) {
            return;
        }

        $email = $userBadge->getUserIdentifier();
        $user = $this->userRepository->findByEmail($email);

        if (!$user instanceof User) {
            return;
        }

        // Reset failed attempts if the previous lock has expired (fresh start)
        if ($user->isLockExpired()) {
            $user->resetFailedLoginAttempts();
        }

        // Record failed login attempt
        $user->recordFailedLoginAttempt();
        $this->userRepository->save($user);

        // Add flash message if account is now locked
        if ($user->isLocked()) {
            $request = $this->requestStack->getCurrentRequest();
            if (null !== $request && $request->hasSession()) {
                $request->getSession()->getFlashBag()->add(
                    'error',
                    'Too many failed login attempts. Your account has been temporarily locked for 15 minutes.'
                );
            }
        }
    }
}
