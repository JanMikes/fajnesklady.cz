<?php

declare(strict_types=1);

namespace App\Service\Order;

final readonly class CompletionPageViewModel
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public string $headline,
        public string $body,
        public string $statusUrl,
        public string $ctaLabel,
    ) {
    }
}
