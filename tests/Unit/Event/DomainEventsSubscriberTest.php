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

class DomainEventsSubscriberTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    public function testCollectsAndBuffersEventsOnPostFlush(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $subscriber = new DomainEventsSubscriber();

        $subscriber->postPersist(new PostPersistEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $buffered = $subscriber->popBufferedEvents();
        $this->assertCount(1, $buffered);
        $this->assertSame($event, $buffered[0]);
    }

    public function testCollectsEventsFromPostUpdate(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $subscriber = new DomainEventsSubscriber();

        $subscriber->postUpdate(new PostUpdateEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $buffered = $subscriber->popBufferedEvents();
        $this->assertCount(1, $buffered);
        $this->assertSame($event, $buffered[0]);
    }

    public function testCollectsEventsFromPostRemove(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $subscriber = new DomainEventsSubscriber();

        $subscriber->postRemove(new PostRemoveEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $buffered = $subscriber->popBufferedEvents();
        $this->assertCount(1, $buffered);
        $this->assertSame($event, $buffered[0]);
    }

    public function testIgnoresEntitiesWithoutEventsInterface(): void
    {
        $entity = new \stdClass();

        $subscriber = new DomainEventsSubscriber();

        $subscriber->postPersist(new PostPersistEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $this->assertSame([], $subscriber->popBufferedEvents());
    }

    public function testResetClearsCollectedEvents(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $subscriber = new DomainEventsSubscriber();

        $subscriber->postPersist(new PostPersistEventArgs($entity, $this->entityManager));
        $subscriber->reset();
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $this->assertSame([], $subscriber->popBufferedEvents());
    }

    public function testBuffersMultipleEventsFromMultipleEntities(): void
    {
        $event1 = new \stdClass();
        $entity1 = $this->createEntityWithEvent($event1);

        $event2 = new \stdClass();
        $entity2 = $this->createEntityWithEvent($event2);

        $subscriber = new DomainEventsSubscriber();

        $subscriber->postPersist(new PostPersistEventArgs($entity1, $this->entityManager));
        $subscriber->postPersist(new PostPersistEventArgs($entity2, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $buffered = $subscriber->popBufferedEvents();
        $this->assertCount(2, $buffered);
        $this->assertSame($event1, $buffered[0]);
        $this->assertSame($event2, $buffered[1]);
    }

    public function testPopBufferedEventsClearsBuffer(): void
    {
        $event = new \stdClass();
        $entity = $this->createEntityWithEvent($event);

        $subscriber = new DomainEventsSubscriber();

        $subscriber->postPersist(new PostPersistEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $this->assertCount(1, $subscriber->popBufferedEvents());
        $this->assertSame([], $subscriber->popBufferedEvents());
    }

    public function testDeleteEventBufferedOnRemove(): void
    {
        $entity = $this->createEntityWithDeleteEvent();

        $subscriber = new DomainEventsSubscriber();

        $subscriber->preRemove(new PreRemoveEventArgs($entity, $this->entityManager));
        $subscriber->postRemove(new PostRemoveEventArgs($entity, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));

        $buffered = $subscriber->popBufferedEvents();
        $this->assertCount(1, $buffered);
        $this->assertInstanceOf(TestDeleteDomainEvent::class, $buffered[0]);
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
