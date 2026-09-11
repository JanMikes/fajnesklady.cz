<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends the browser-facing security headers.
 *
 * These were previously declared in `config/packages/prod/framework.php` under
 * `framework.http_client.default_options.headers` — which configures headers on
 * *outgoing* HttpClient requests (GoPay, ARES, Fakturoid), not on responses to
 * browsers. Visitors therefore received none of them while the config read as
 * though they did. This listener is the actual implementation; the http_client
 * block was removed.
 *
 * `X-Frame-Options` is the only one that can break something, so it is skipped
 * on the GoPay return routes — see SELF_FRAMEABLE_ROUTES.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class SecurityHeadersSubscriber
{
    /**
     * Routes GoPay may land the customer on after a payment.
     *
     * Our own payment pages frame GoPay's inline gateway, which our headers do
     * not affect — a frame is governed by the framed site's headers, not the
     * framing one's. The reverse is the open question: GoPay's documentation
     * does not state whether the merchant's `return_url` is opened as a
     * top-level navigation or inside the gateway iframe. For 3DS and bank
     * redirects it documents a full-page hop through the redirect gateway, so
     * top-level is the likely answer in the flows that matter under PSD2 SCA —
     * but "likely" is not a basis for risking live payments, so these four
     * routes go without the header until that is confirmed.
     *
     * They are transient result pages with no state-changing controls, so the
     * clickjacking exposure of leaving them out is negligible.
     *
     * @var list<string>
     */
    private const SELF_FRAMEABLE_ROUTES = [
        'public_payment_return',
        'public_debt_payment_return',
        'public_fine_payment_return',
        'public_contract_card_setup_return',
    ];

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        // Never clobber a header a controller set deliberately.
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // HSTS is meaningless — and per RFC 6797 § 7.2 must be ignored — over
        // plain HTTP, so it is only sent on a secure connection. That also
        // keeps it out of local dev and the test client.
        if ($request->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $route = $request->attributes->get('_route');

        if (!in_array($route, self::SELF_FRAMEABLE_ROUTES, true) && !$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }
    }
}
