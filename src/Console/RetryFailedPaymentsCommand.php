<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\CancelRecurringPaymentCommand;
use App\Command\ChargeRecurringPaymentCommand;
use App\Entity\Contract;
use App\Enum\TerminationReason;
use App\Event\ContractTerminatedDueToPaymentFailure;
use App\Event\RecurringPaymentFailed;
use App\Repository\ContractRepository;
use App\Service\ContractService;
use App\Service\GoPay\GoPayException;
use App\Service\GoPay\PaymentNotConfirmedException;
use App\Service\Messenger\HandlerFailureUnwrap;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
        private readonly ManagerRegistry $doctrine,
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
            $attemptsBefore = $contract->failedBillingAttempts;
            $isLastAttempt = $attemptsBefore >= 2; // 2 in DB = this is the 3rd attempt

            try {
                $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
                ++$successCount;
                $io->text(sprintf('  [OK] Contract %s retry successful.', $contract->id));
            } catch (\Throwable $rawException) {
                $exception = HandlerFailureUnwrap::unwrap($rawException);
                $isExpectedFailure = $exception instanceof GoPayException
                    || $exception instanceof PaymentNotConfirmedException;

                $this->recordRetryFailure($contract, $exception, $isExpectedFailure, $isLastAttempt, $now, $io);

                if ($isExpectedFailure && $isLastAttempt) {
                    if ($this->terminateForPaymentDefault($contract, $now, $io)) {
                        ++$cancelledCount;
                    }
                } elseif ($isExpectedFailure) {
                    $io->text(sprintf('  [RETRY LATER] Contract %s failed attempt %d, will retry later.', $contract->id, $contract->failedBillingAttempts));
                }
            }
        }

        $io->success(sprintf('Processed: %d success, %d cancelled.', $successCount, $cancelledCount));

        return Command::SUCCESS;
    }

    /**
     * Record a retry failure (or log an unexpected error). Wrapped so a
     * follow-up problem (closed EntityManager, event-bus error) cannot
     * stop the rest of the run — the contract will be picked up again on
     * the next cron pass.
     */
    private function recordRetryFailure(
        Contract $contract,
        \Throwable $exception,
        bool $isExpectedFailure,
        bool $isLastAttempt,
        \DateTimeImmutable $now,
        SymfonyStyle $io,
    ): void {
        try {
            if ($isExpectedFailure) {
                $contract->recordFailedBillingAttempt($now);
                $this->getEntityManager()->flush();

                $this->eventBus->dispatch(new RecurringPaymentFailed(
                    contractId: $contract->id,
                    attempt: $contract->failedBillingAttempts,
                    reason: $exception->getMessage(),
                    occurredOn: $now,
                ));

                $this->logger->error('Recurring payment retry failed', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'attempt' => $contract->failedBillingAttempts,
                    'is_last_attempt' => $isLastAttempt,
                    'exception' => $exception,
                ]);
            } else {
                $this->logger->error('Recurring payment retry failed (unexpected)', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'exception' => $exception,
                ]);
            }
            $io->error(sprintf('  [FAIL] Contract %s: %s', $contract->id, $exception->getMessage()));
        } catch (\Throwable $followUpException) {
            $this->logger->critical('Failed to record retry failure — resetting EntityManager and continuing', [
                'contract_id' => $contract->id->toRfc4122(),
                'original_exception' => $exception,
                'follow_up_exception' => $followUpException,
            ]);

            $this->doctrine->resetManager();
        }
    }

    /**
     * Cancel recurring payment, calculate outstanding debt, terminate contract,
     * and emit the payment-default event. Wrapped in its own try/catch so a
     * follow-up failure here cannot stop the rest of the cron loop.
     *
     * @return bool true if the termination was recorded successfully
     */
    private function terminateForPaymentDefault(Contract $contract, \DateTimeImmutable $now, SymfonyStyle $io): bool
    {
        try {
            $this->commandBus->dispatch(new CancelRecurringPaymentCommand($contract));

            $outstandingDebt = $this->contractService->calculateOutstandingDebt($contract, $now);
            if ($outstandingDebt > 0) {
                $contract->setOutstandingDebt($outstandingDebt);
            }

            $this->contractService->terminateContract($contract, $now, TerminationReason::PAYMENT_FAILURE);
            $this->getEntityManager()->flush();

            $this->eventBus->dispatch(new ContractTerminatedDueToPaymentFailure(
                contractId: $contract->id,
                outstandingDebtAmount: $outstandingDebt,
                occurredOn: $now,
            ));

            $io->warning(sprintf(
                '  [PAYMENT DEFAULT] Contract %s terminated. Outstanding debt: %s Kč',
                $contract->id,
                number_format($outstandingDebt / 100, 2, ',', ' '),
            ));

            return true;
        } catch (\Throwable $cancelException) {
            $this->logger->error('Failed to cancel/terminate contract after payment failure', [
                'contract_id' => $contract->id->toRfc4122(),
                'exception' => $cancelException,
            ]);
            $io->error(sprintf('  [ERROR] Contract %s: Failed to cancel/terminate: %s', $contract->id, $cancelException->getMessage()));

            $this->doctrine->resetManager();

            return false;
        }
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Default Doctrine manager is not an ORM EntityManager.');
        }

        if (!$manager->isOpen()) {
            $this->doctrine->resetManager();
            $reset = $this->doctrine->getManager();
            \assert($reset instanceof EntityManagerInterface);

            return $reset;
        }

        return $manager;
    }
}
