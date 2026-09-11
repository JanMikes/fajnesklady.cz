<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Command\MarkAnalyticsEventsPushedCommand;
use App\Entity\Order;
use App\Repository\AnalyticsEventRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hands a controller the measurement events still waiting for a browser, and
 * marks them delivered in the same breath.
 *
 * Call it from a controller the customer lands on after the moment of truth;
 * pass the result to components/_analytics_datalayer.html.twig.
 */
final readonly class AnalyticsEventFlusher
{
    public function __construct(
        private AnalyticsEventRepository $analyticsEventRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return list<array{name: string, payload: array<string, scalar|null>}>
     */
    public function flushFor(Order $order): array
    {
        $events = $this->analyticsEventRepository->findUnpushedForOrder($order);

        if ([] === $events) {
            return [];
        }

        // Read the payloads out before dispatching: the dispatch commits, and
        // anything we touched afterwards would be reasoning about entities the
        // transaction has already moved past.
        $rendered = array_map(
            static fn ($event): array => ['name' => $event->name, 'payload' => $event->payload],
            $events,
        );

        $this->commandBus->dispatch(new MarkAnalyticsEventsPushedCommand(
            analyticsEventIds: array_map(static fn ($event) => $event->id, $events),
        ));

        return $rendered;
    }
}
