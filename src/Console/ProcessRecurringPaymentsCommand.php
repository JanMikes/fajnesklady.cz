<?php

declare(strict_types=1);

namespace App\Console;

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
    name: 'app:process-recurring-payments',
    description: 'Process due recurring payments for unlimited contracts',
)]
final class ProcessRecurringPaymentsCommand extends Command
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

        $contracts = $this->contractRepository->findDueForBilling($now);

        if (0 === count($contracts)) {
            $io->info('No contracts due for billing.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contracts due for billing.', count($contracts)));

        $successCount = 0;
        $failureCount = 0;

        foreach ($contracts as $contract) {
            try {
                $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
                ++$successCount;
                $io->text(sprintf('  [OK] Contract %s charged successfully.', $contract->id));
            } catch (\Exception $e) {
                ++$failureCount;
                $io->error(sprintf('  [FAIL] Contract %s: %s', $contract->id, $e->getMessage()));
            }
        }

        $io->success(sprintf('Processed: %d success, %d failures.', $successCount, $failureCount));

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
