<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\FineRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FineExtension extends AbstractExtension
{
    private ?int $cachedCount = null;

    public function __construct(
        private readonly FineRepository $fineRepository,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('fines_unpaid_count', $this->finesUnpaidCount(...)),
        ];
    }

    public function finesUnpaidCount(): int
    {
        // Memoised because layout.html.twig calls this twice (desktop + mobile sidebars).
        return $this->cachedCount ??= $this->fineRepository->countUnpaid();
    }
}
