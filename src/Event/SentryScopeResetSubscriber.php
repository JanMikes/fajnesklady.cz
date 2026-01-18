<?php

declare(strict_types=1);

namespace App\Event;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resets Sentry scope between requests for FrankenPHP worker mode.
 *
 * In worker mode, the same PHP process handles multiple requests,
 * so we need to clear breadcrumbs and scope data to prevent
 * data leaking between requests.
 *
 * Priority 512 ensures this runs before other listeners add breadcrumbs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class SentryScopeResetSubscriber
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->hub->configureScope(static function (Scope $scope): void {
            $scope->clear();
            $scope->setPropagationContext(PropagationContext::fromDefaults());
        });
    }
}
