<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Order;
use App\Repository\AnalyticsEventRepository;

/**
 * Hands a controller the measurement events still waiting for a browser.
 *
 * It deliberately does NOT mark them delivered. Delivery is confirmed by the
 * browser itself, via AnalyticsEventAckController, once the push has actually
 * run — see components/_analytics_datalayer.html.twig.
 *
 * The reason is that the status page is reached through a link in an e-mail,
 * and plenty of things open such a link without being the customer: mail
 * security scanners, link previewers, corporate gateways. They fetch the HTML
 * and never run JavaScript. Marking on render handed the conversion to one of
 * those and left the customer's own click with nothing to push — a real
 * conversion lost, silently, with the database claiming success.
 *
 * The trade-off runs the other way now: an event can be pushed twice if a page
 * is loaded twice before the acknowledgement lands, or if the request fails.
 * That is the better risk — GA4 de-duplicates on `transaction_id`, and a
 * duplicate is visible in reports where a miss never is.
 */
final readonly class AnalyticsEventFlusher
{
    public function __construct(
        private AnalyticsEventRepository $analyticsEventRepository,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, payload: array<string, scalar|null>}>
     */
    public function pendingFor(Order $order): array
    {
        return array_map(
            static fn ($event): array => [
                'id' => $event->id->toRfc4122(),
                'name' => $event->name,
                'payload' => $event->payload,
            ],
            $this->analyticsEventRepository->findUnpushedForOrder($order),
        );
    }
}
