<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Enum\AllocationStepType;

/**
 * One obligation in an {@see AllocationPlan}, and how much of the available
 * money reached it.
 */
final readonly class AllocationStep
{
    public function __construct(
        public AllocationStepType $type,
        /** What the obligation still needed before this allocation. */
        public int $expected,
        /** What this allocation actually put toward it. */
        public int $allocated,
        /** Whether the obligation is fully covered once this is applied. */
        public bool $fullySettled,
        /** Already covered by earlier transfers — context for the admin summary. */
        public int $previouslyPaid = 0,
    ) {
    }

    public function shortfall(): int
    {
        return max(0, $this->expected - $this->allocated);
    }
}
