<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EntityWithEvents;
use App\Entity\HasEvents;
use PHPUnit\Framework\TestCase;

class HasEventsTest extends TestCase
{
    public function testRecordAndPopEvents(): void
    {
        $entity = $this->createEntityWithEvents();

        $event1 = new \stdClass();
        $event2 = new \stdClass();

        $entity->recordThat($event1);
        $entity->recordThat($event2);

        $events = $entity->popEvents();

        $this->assertCount(2, $events);
        $this->assertSame($event1, $events[0]);
        $this->assertSame($event2, $events[1]);
    }

    public function testPopEventsClearsCollection(): void
    {
        $entity = $this->createEntityWithEvents();

        $entity->recordThat(new \stdClass());
        $entity->popEvents();

        $this->assertSame([], $entity->popEvents());
    }

    public function testPopEventsReturnsEmptyArrayWhenNoEvents(): void
    {
        $entity = $this->createEntityWithEvents();

        $this->assertSame([], $entity->popEvents());
    }

    public function testEventsAreReturnedInOrder(): void
    {
        $entity = $this->createEntityWithEvents();

        $event1 = new \stdClass();
        $event2 = new \stdClass();
        $event3 = new \stdClass();

        $entity->recordThat($event1);
        $entity->recordThat($event2);
        $entity->recordThat($event3);

        $events = $entity->popEvents();

        $this->assertSame($event1, $events[0]);
        $this->assertSame($event2, $events[1]);
        $this->assertSame($event3, $events[2]);
    }

    private function createEntityWithEvents(): EntityWithEvents
    {
        return new class () implements EntityWithEvents {
            use HasEvents;
        };
    }
}
