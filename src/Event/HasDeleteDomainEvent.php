<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Marks an entity class to automatically dispatch a deletion event.
 * The event class must implement DeleteDomainEvent interface.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class HasDeleteDomainEvent
{
    /**
     * @param class-string<DeleteDomainEvent> $eventClass
     */
    public function __construct(
        public string $eventClass,
    ) {
    }
}
