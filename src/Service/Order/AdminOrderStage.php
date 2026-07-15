<?php

declare(strict_types=1);

namespace App\Service\Order;

/**
 * The one-glance answer to "where is this order right now?" for the admin
 * order detail header: a single lifecycle stage, the list of things that are
 * wrong, and what the system is waiting for next.
 */
final readonly class AdminOrderStage
{
    public const string TONE_GREEN = 'green';
    public const string TONE_AMBER = 'amber';
    public const string TONE_RED = 'red';
    public const string TONE_BLUE = 'blue';
    public const string TONE_GRAY = 'gray';

    /**
     * @param list<string> $problems
     */
    public function __construct(
        public string $label,
        public ?string $sublabel,
        public string $tone,
        public array $problems,
        public ?string $nextStep,
    ) {
    }

    public function hasProblems(): bool
    {
        return [] !== $this->problems;
    }
}
