<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\EntityWithEvents;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects domain events from entities and dispatches them after Doctrine flush.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DomainEventsSubscriber implements ResetInterface
{
    /**
     * @var array<object>
     */
    private array $collectedEvents = [];

    /**
     * @var array<string, object>
     */
    private array $pendingDeleteEvents = [];

    public function __construct(
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collectEventsFromEntity($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collectEventsFromEntity($args->getObject());
    }

    /**
     * Pre-remove: Create deletion events while entity data is still available.
     */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        $this->createDeleteEventFromAttribute($entity);
    }

    /**
     * Post-remove: Collect any events recorded by the entity and move pending delete event.
     */
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        $this->collectEventsFromEntity($entity);
        $this->collectPendingDeleteEvent($entity);
    }

    /**
     * Post-flush: Dispatch all collected events.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $events = $this->collectedEvents;
        $this->collectedEvents = [];
        $this->pendingDeleteEvents = [];

        foreach ($events as $event) {
            $this->eventBus->dispatch($event);
        }
    }

    /**
     * Reset state between requests (important for long-running processes).
     */
    public function reset(): void
    {
        $this->collectedEvents = [];
        $this->pendingDeleteEvents = [];
    }

    private function collectEventsFromEntity(object $entity): void
    {
        if (!$entity instanceof EntityWithEvents) {
            return;
        }

        foreach ($entity->popEvents() as $event) {
            $this->collectedEvents[] = $event;
        }
    }

    private function createDeleteEventFromAttribute(object $entity): void
    {
        $reflection = new \ReflectionClass($entity);
        $attributes = $reflection->getAttributes(HasDeleteDomainEvent::class);

        if ([] === $attributes) {
            return;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var class-string<DeleteDomainEvent> $eventClass */
        $eventClass = $attribute->eventClass;

        $this->pendingDeleteEvents[spl_object_hash($entity)] = $eventClass::fromEntity($entity);
    }

    private function collectPendingDeleteEvent(object $entity): void
    {
        $hash = spl_object_hash($entity);

        if (!isset($this->pendingDeleteEvents[$hash])) {
            return;
        }

        $this->collectedEvents[] = $this->pendingDeleteEvents[$hash];
    }
}
