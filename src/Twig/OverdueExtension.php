<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\ContractRepository;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OverdueExtension extends AbstractExtension
{
    private ?int $cachedCount = null;

    public function __construct(
        private readonly ContractRepository $contractRepository,
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
        // Scalar SQL count — avoids hydrating the full overdue contract list just to render
        // the sidebar/mobile-menu badge. Memoised per request because layout.html.twig calls
        // overdue_count() twice (sidebar + mobile menu).
        return $this->cachedCount ??= $this->contractRepository->countOverdueContracts($this->clock->now());
    }
}
