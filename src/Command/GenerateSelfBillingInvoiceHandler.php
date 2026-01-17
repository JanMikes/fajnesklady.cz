<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SelfBillingInvoice;
use App\Repository\UserRepository;
use App\Service\SelfBillingService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateSelfBillingInvoiceHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private SelfBillingService $selfBillingService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GenerateSelfBillingInvoiceCommand $command): SelfBillingInvoice
    {
        $landlord = $this->userRepository->get($command->landlordId);
        $now = $this->clock->now();

        return $this->selfBillingService->getOrCreateInvoice(
            $landlord,
            $command->year,
            $command->month,
            $now,
        );
    }
}
