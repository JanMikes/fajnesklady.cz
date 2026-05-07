<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OverdueExtension extends AbstractExtension
{
    private ?int $cachedCount = null;

    public function __construct(
        private readonly OverdueChecker $overdueChecker,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('overdue_count', $this->overdueCount(...)),
        ];
    }

    public function overdueCount(): int
    {
        return $this->cachedCount ??= $this->overdueChecker->summarise($this->clock->now())->count;
    }
}
