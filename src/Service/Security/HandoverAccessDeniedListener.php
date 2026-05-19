<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Converts AccessDeniedException on the three portal handover routes into a
 * redirect to /login. Required because LoginController bounces an already
 * authenticated wrong-account user back to home — so we must logout first.
 *
 * Only matches Security\Core\Exception\AccessDeniedException (thrown by
 * IsGranted / denyAccessUnlessGranted / voters). Does NOT match
 * HttpKernel\Exception\AccessDeniedHttpException — the public signed-URL
 * controller throws the HTTP variant for a bad signature, and we don't want
 * to push those users through a login flow.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final readonly class HandoverAccessDeniedListener
{
    private const array HANDLED_ROUTES = [
        'portal_landlord_handover_view',
        'portal_landlord_handover_generate_code',
        'portal_user_handover_view',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof AccessDeniedException) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!in_array($route, self::HANDLED_ROUTES, true)) {
            return;
        }

        // Wrong-account case: clear the current token so /login renders the form
        // instead of bouncing on LoginController's "already logged in → home" branch.
        if (null !== $this->security->getUser()) {
            $this->security->logout(validateCsrfToken: false);
        }

        $loginUrl = $this->urlGenerator->generate(
            'app_login',
            ['_target_path' => $request->getUri()],
        );

        $event->setResponse(new RedirectResponse($loginUrl));
    }
}
