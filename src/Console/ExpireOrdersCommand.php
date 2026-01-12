<?php

declare(strict_types=1);

namespace App\Console;

use App\Service\OrderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command to expire orders that have passed their expiration date.
 *
 * Should be run as a scheduled task (e.g., cron job every hour).
 */
#[AsCommand(
    name: 'app:expire-orders',
    description: 'Expire orders that have passed their expiration date',
)]
final class ExpireOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $count = $this->orderService->expireOverdueOrders($now);

        if ($count > 0) {
            $io->success(sprintf('Expired %d order(s).', $count));
        } else {
            $io->info('No orders to expire.');
        }

        return Command::SUCCESS;
    }
}
