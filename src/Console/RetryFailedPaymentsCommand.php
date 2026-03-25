<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\CancelRecurringPaymentCommand;
use App\Command\ChargeRecurringPaymentCommand;
use App\Event\ContractTerminated;
use App\Repository\ContractRepository;
use App\Service\ContractService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:retry-failed-payments',
    description: 'Retry failed recurring payments (after 3 days) or cancel if retry also fails',
)]
final class RetryFailedPaymentsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ContractService $contractService,
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
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
            // Check attempt count BEFORE dispatch — the handler increments it in-memory
            // but doctrine_transaction middleware rolls back on exception, so the DB value
            // stays at the pre-dispatch level. We use the DB value to decide next action.
            $attemptsBefore = $contract->failedBillingAttempts;
            $isLastAttempt = $attemptsBefore >= 2; // 2 in DB = this is the 3rd attempt

            try {
                $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
                ++$successCount;
                $io->text(sprintf('  [OK] Contract %s retry successful.', $contract->id));
            } catch (\Exception) {
                if ($isLastAttempt) {
                    // Third failure — cancel recurring payment and terminate contract
                    try {
                        $this->commandBus->dispatch(new CancelRecurringPaymentCommand($contract));
                        $this->contractService->terminateContract($contract, $now);
                        $this->eventBus->dispatch(new ContractTerminated(
                            contractId: $contract->id,
                            occurredOn: $now,
                        ));
                        ++$cancelledCount;
                        $io->warning(sprintf('  [TERMINATED] Contract %s terminated after 3 payment failures.', $contract->id));
                    } catch (\Exception $e) {
                        $io->error(sprintf('  [ERROR] Contract %s: Failed to cancel/terminate: %s', $contract->id, $e->getMessage()));
                    }
                } else {
                    $io->text(sprintf('  [RETRY LATER] Contract %s failed attempt %d, will retry later.', $contract->id, $attemptsBefore + 1));
                }
            }
        }

        $io->success(sprintf('Processed: %d success, %d cancelled.', $successCount, $cancelledCount));

        return Command::SUCCESS;
    }
}
