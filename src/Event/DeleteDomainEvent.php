<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Interface for domain events that capture entity data before deletion.
 * The static factory method extracts necessary data while the entity still exists.
 */
interface DeleteDomainEvent
{
    /**
     * Create an event instance from the entity before it is removed.
     * This allows capturing entity data (ID, relations, etc.) before Doctrine removes it.
     */
    public static function fromEntity(object $entity): static;
}
