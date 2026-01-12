<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Trait providing EntityWithEvents implementation.
 * Use this trait in entities that need to record domain events.
 */
trait HasEvents
{
    /**
     * @var array<object>
     */
    private array $domainEvents = [];

    public function recordThat(object $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * @return array<object>
     */
    public function popEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }
}
