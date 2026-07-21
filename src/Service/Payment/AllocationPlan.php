<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Enum\AllocationStepType;

/**
 * What a given amount of money would settle for a given order, computed without
 * touching anything. The admin confirm screen renders exactly the plan that
 * {@see PaymentAllocator::apply()} will then execute.
 */
final readonly class AllocationPlan
{
    /**
     * @param list<AllocationStep> $steps
     */
    public function __construct(
        public array $steps,
        /** Money on the table: the incoming transfer plus any contract credit. */
        public int $available,
        /** Credit drawn on to build this plan (already included in $available). */
        public int $creditUsed,
        /**
         * Money that reached no obligation and has nowhere to go — only possible
         * for an order with no contract, which has no credit balance to hold it.
         */
        public int $unallocated,
    ) {
    }

    public function totalAllocated(): int
    {
        $total = 0;
        foreach ($this->steps as $step) {
            if (AllocationStepType::CREDIT !== $step->type) {
                $total += $step->allocated;
            }
        }

        return $total;
    }

    public function settlesEverything(): bool
    {
        foreach ($this->steps as $step) {
            if (AllocationStepType::CREDIT === $step->type) {
                continue;
            }
            if (!$step->fullySettled) {
                return false;
            }
        }

        return true;
    }

    public function step(AllocationStepType $type): ?AllocationStep
    {
        foreach ($this->steps as $step) {
            if ($type === $step->type) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The obligations this money touched, credit aside — what the admin confirm
     * screen lists.
     *
     * @return list<AllocationStep>
     */
    public function obligationSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (AllocationStep $step): bool => AllocationStepType::CREDIT !== $step->type,
        ));
    }

    public function creditAdded(): int
    {
        $step = $this->step(AllocationStepType::CREDIT);

        return null !== $step ? $step->allocated : 0;
    }
}
