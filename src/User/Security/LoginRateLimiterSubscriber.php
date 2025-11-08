<?php

declare(strict_types=1);

namespace App\User\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginRateLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactory $loginLimiter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['onCheckPassport', 9999],
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        // Create a limiter based on IP address
        $limiter = $this->loginLimiter->create($request->getClientIp() ?? 'unknown');

        // Consume a token
        if (false === $limiter->consume(1)->isAccepted()) {
            throw new \Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException();
        }
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // Additional rate limiting could be added here if needed
    }
}
