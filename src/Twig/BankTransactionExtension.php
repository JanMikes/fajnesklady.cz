<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\BankTransactionRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BankTransactionExtension extends AbstractExtension
{
    public function __construct(
        private readonly BankTransactionRepository $bankTransactionRepository,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('unmatched_bank_transactions_count', $this->getUnmatchedCount(...)),
        ];
    }

    public function getUnmatchedCount(): int
    {
        return $this->bankTransactionRepository->countUnmatched();
    }
}
