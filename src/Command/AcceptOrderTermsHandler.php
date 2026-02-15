<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AcceptOrderTermsHandler
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AcceptOrderTermsCommand $command): void
    {
        $command->order->acceptTerms($this->clock->now());
    }
}
