<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\AnalyticsEvent;
use App\Repository\AnalyticsEventRepository;
use App\Repository\OrderRepository;
use App\Service\Analytics\AnalyticsPayloadFactory;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Records the "binding order confirmed" measurement event.
 *
 * Listens to OrderCreated, which the Order entity records in its constructor —
 * so this fires when the row genuinely exists and the command-bus transaction
 * is about to commit, never on a click that later failed validation, hit a
 * taken storage unit, or lost the double-submit guard.
 */
#[AsMessageHandler]
final readonly class RecordOrderCreatedAnalyticsEventHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private AnalyticsEventRepository $analyticsEventRepository,
        private AnalyticsPayloadFactory $payloadFactory,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(OrderCreated $event): void
    {
        $order = $this->orderRepository->get($event->orderId);

        // Admin onboarding creates orders on the operator's behalf: no customer
        // browser, no campaign, no conversion. Counting them would inflate Ads
        // with rentals that never came through the site.
        if (null !== $order->createdByAdmin) {
            return;
        }

        $this->analyticsEventRepository->save(new AnalyticsEvent(
            id: $this->identityProvider->next(),
            order: $order,
            name: AnalyticsEvent::ORDER_CREATED,
            payload: $this->payloadFactory->orderCreated($order),
            occurredAt: $event->occurredOn,
        ));
    }
}
