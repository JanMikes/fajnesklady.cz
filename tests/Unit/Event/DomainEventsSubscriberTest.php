<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\EntityWithEvents;
use App\Entity\HasEvents;
use App\Event\DeleteDomainEvent;
use App\Event\DomainEventsSubscriber;
use App\Event\HasDeleteDomainEvent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class DomainEventsSubscriberTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testCollectsAndDispatchesEventsOnPostFlush(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->once())
            ->method('dispatch')
            ->with($event)
            ->willReturn(new Envelope($event));

        $subscriber = new DomainEventsSubscriber($eventBus);

        $subscriber->postPersist(new PostPersistEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    public function testCollectsEventsFromPostUpdate(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->once())
            ->method('dispatch')
            ->with($event)
            ->willReturn(new Envelope($event));

        $subscriber = new DomainEventsSubscriber($eventBus);

        $subscriber->postUpdate(new PostUpdateEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    public function testCollectsEventsFromPostRemove(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->once())
            ->method('dispatch')
            ->with($event)
            ->willReturn(new Envelope($event));

        $subscriber = new DomainEventsSubscriber($eventBus);

        $subscriber->postRemove(new PostRemoveEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    public function testIgnoresEntitiesWithoutEventsInterface(): void
    {
        $entity = new \stdClass();

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->never())->method('dispatch');

        $subscriber = new DomainEventsSubscriber($eventBus);

        $subscriber->postPersist(new PostPersistEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    public function testResetClearsCollectedEvents(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->never())->method('dispatch');

        $subscriber = new DomainEventsSubscriber($eventBus);

        $subscriber->postPersist(new PostPersistEventArgs($entity, $this->entityManager));
        $subscriber->reset();
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    public function testDispatchesMultipleEventsFromMultipleEntities(): void
    {
        $event1 = new \stdClass();
        $entity1 = $this->createEntityWithEvent($event1);

        $event2 = new \stdClass();
        $entity2 = $this->createEntityWithEvent($event2);

        $dispatchedEvents = [];
        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;

                return new Envelope($event);
            });

        $subscriber = new DomainEventsSubscriber($eventBus);

        $subscriber->postPersist(new PostPersistEventArgs($entity1, $this->entityManager));
        $subscriber->postPersist(new PostPersistEventArgs($entity2, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $this->assertCount(2, $dispatchedEvents);
        $this->assertSame($event1, $dispatchedEvents[0]);
        $this->assertSame($event2, $dispatchedEvents[1]);
    }

    public function testDeleteEventDispatchedOnRemove(): void
    {
        $entity = $this->createEntityWithDeleteEvent();

        $dispatchedEvents = [];
        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;

                return new Envelope($event);
            });

        $subscriber = new DomainEventsSubscriber($eventBus);

        $subscriber->preRemove(new PreRemoveEventArgs($entity, $this->entityManager));
        $subscriber->postRemove(new PostRemoveEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $this->assertCount(1, $dispatchedEvents);
        $this->assertInstanceOf(TestDeleteDomainEvent::class, $dispatchedEvents[0]);
    }

    private function createEntityWithEvent(object $event): EntityWithEvents
    {
        $entity = new class () implements EntityWithEvents {
            use HasEvents;
        };
        $entity->recordThat($event);

        return $entity;
    }

    private function createEntityWithDeleteEvent(): object
    {
        return new #[HasDeleteDomainEvent(eventClass: TestDeleteDomainEvent::class)] class {
        };
    }
}

/**
 * @internal
 */
final readonly class TestDeleteDomainEvent implements DeleteDomainEvent
{
    public function __construct(
        public string $entityClass,
    ) {
    }

    public static function fromEntity(object $entity): static
    {
        return new self($entity::class);
    }
}
