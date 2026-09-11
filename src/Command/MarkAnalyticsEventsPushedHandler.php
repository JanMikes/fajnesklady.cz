<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AnalyticsEventRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks measurement events as delivered to a browser.
 *
 * Dispatched from the controller that is about to render them. It rides the
 * command bus so doctrine_transaction commits the write — a controller that
 * mutated the entities itself would flush nothing (see .claude/MESSENGER.md §5).
 *
 * Marking before the render rather than after means a response that never
 * arrives loses the event. That is the right way round: an undelivered
 * conversion is a missing row in a report, a double-delivered one corrupts
 * Ads bidding.
 */
#[AsMessageHandler]
final readonly class MarkAnalyticsEventsPushedHandler
{
    public function __construct(
        private AnalyticsEventRepository $analyticsEventRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MarkAnalyticsEventsPushedCommand $command): void
    {
        $now = $this->clock->now();

        foreach ($command->analyticsEventIds as $id) {
            $this->analyticsEventRepository->find($id)?->markPushed($now);
        }
    }
}
