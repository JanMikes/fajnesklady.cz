<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Operations\OperationsAlertsBuilder;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OperationsExtension extends AbstractExtension
{
    private ?int $cachedCount = null;

    public function __construct(
        private readonly OperationsAlertsBuilder $builder,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('operations_alerts_count', $this->operationsAlertsCount(...)),
        ];
    }

    public function operationsAlertsCount(): int
    {
        // Memoised because layout.html.twig calls this twice (desktop + mobile sidebars).
        return $this->cachedCount ??= $this->builder->totalPendingCount($this->clock->now());
    }
}
