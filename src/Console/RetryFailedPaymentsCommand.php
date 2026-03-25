<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\CancelRecurringPaymentCommand;
use App\Command\ChargeRecurringPaymentCommand;
use App\Enum\TerminationReason;
use App\Event\ContractTerminatedDueToPaymentFailure;
use App\Repository\ContractRepository;
use App\Service\ContractService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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
            } catch (\Exception $e) {
                $this->logger->error('Recurring payment retry failed', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'attempt' => $attemptsBefore + 1,
                    'is_last_attempt' => $isLastAttempt,
                    'exception' => $e,
                ]);

                if ($isLastAttempt) {
                    // Third failure — cancel recurring, terminate with debt tracking
                    try {
                        $this->commandBus->dispatch(new CancelRecurringPaymentCommand($contract));

                        // Calculate outstanding debt before termination
                        $outstandingDebt = $this->contractService->calculateOutstandingDebt($contract, $now);
                        if ($outstandingDebt > 0) {
                            $contract->setOutstandingDebt($outstandingDebt);
                        }

                        $this->contractService->terminateContract($contract, $now, TerminationReason::PAYMENT_FAILURE);

                        $this->eventBus->dispatch(new ContractTerminatedDueToPaymentFailure(
                            contractId: $contract->id,
                            outstandingDebtAmount: $outstandingDebt,
                            occurredOn: $now,
                        ));
                        ++$cancelledCount;
                        $io->warning(sprintf(
                            '  [PAYMENT DEFAULT] Contract %s terminated. Outstanding debt: %s Kč',
                            $contract->id,
                            number_format($outstandingDebt / 100, 2, ',', ' '),
                        ));
                    } catch (\Exception $e) {
                        $this->logger->error('Failed to cancel/terminate contract after payment failure', [
                            'contract_id' => $contract->id->toRfc4122(),
                            'exception' => $e,
                        ]);
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
