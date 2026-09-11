<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

/**
 * @see MarkAnalyticsEventsPushedHandler
 */
final readonly class MarkAnalyticsEventsPushedCommand
{
    /**
     * @param list<Uuid> $analyticsEventIds
     */
    public function __construct(
        public array $analyticsEventIds,
    ) {
    }
}
