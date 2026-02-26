<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Event\DomainEventsSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Dispatches buffered domain events AFTER the command bus transaction commits.
 *
 * Must be placed BEFORE doctrine_transaction in the command bus middleware stack.
 * This ensures event handlers run in their own transaction (via event bus's doctrine_transaction),
 * completely isolated from the command's transaction.
 *
 * Flow: command handler → doctrine_transaction commits → this middleware dispatches events → event bus handles them.
 */
final readonly class DispatchDomainEventsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DomainEventsSubscriber $domainEventsSubscriber,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $envelope = $stack->next()->handle($envelope, $stack);

        // After doctrine_transaction committed, dispatch buffered domain events.
        // Loop handles events that trigger further events (e.g. OrderCompleted → ContractCreated).
        while ([] !== $events = $this->domainEventsSubscriber->popBufferedEvents()) {
            foreach ($events as $event) {
                try {
                    $this->eventBus->dispatch($event);
                } catch (\Throwable $e) {
                    $this->logger->error('Domain event failed, dispatching to async for retry', [
                        'event' => $event::class,
                        'exception' => $e,
                    ]);
                    $this->eventBus->dispatch(
                        Envelope::wrap($event)->with(new TransportNamesStamp(['async'])),
                    );
                }
            }
        }

        return $envelope;
    }
}
