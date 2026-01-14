<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\CancelRecurringPaymentCommand;
use App\Command\ChargeRecurringPaymentCommand;
use App\Repository\ContractRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:retry-failed-payments',
    description: 'Retry failed recurring payments (after 3 days) or cancel if retry also fails',
)]
final class RetryFailedPaymentsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $contracts = $this->contractRepository->findNeedingRetry($now);

        if (0 === count($contracts)) {
            $io->info('No contracts need payment retry.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contracts needing payment retry.', count($contracts)));

        $successCount = 0;
        $cancelledCount = 0;

        foreach ($contracts as $contract) {
            try {
                $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
                ++$successCount;
                $io->text(sprintf('  [OK] Contract %s retry successful.', $contract->id));
            } catch (\Exception) {
                // Second failure - cancel recurring payment
                try {
                    $this->commandBus->dispatch(new CancelRecurringPaymentCommand($contract));
                    ++$cancelledCount;
                    $io->warning(sprintf('  [CANCELLED] Contract %s recurring payment cancelled after 2 failures.', $contract->id));
                } catch (\Exception $e) {
                    $io->error(sprintf('  [ERROR] Contract %s: Failed to cancel recurring: %s', $contract->id, $e->getMessage()));
                }
            }
        }

        $io->success(sprintf('Processed: %d success, %d cancelled.', $successCount, $cancelledCount));

        return Command::SUCCESS;
    }
}
