<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Interface for entities that can record domain events.
 * Events are collected by DomainEventsSubscriber and dispatched after Doctrine flush.
 */
interface EntityWithEvents
{
    /**
     * Record a domain event to be dispatched after persistence.
     */
    public function recordThat(object $event): void;

    /**
     * Pop and return all recorded events, clearing the internal collection.
     *
     * @return array<object>
     */
    public function popEvents(): array;
}
