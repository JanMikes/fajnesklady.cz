<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\ChargeRecurringPaymentCommand;
use App\Entity\Contract;
use App\Event\PaymentDemandSent;
use App\Event\RecurringPaymentFailed;
use App\Repository\ContractRepository;
use App\Repository\PlatformSettingsRepository;
use App\Service\AuditLogger;
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
    description: 'Retry failed recurring payments (day 3 and day 7); termination is handled by app:terminate-overdue-contracts',
)]
final class RetryFailedPaymentsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly PlatformSettingsRepository $settingsRepository,
        private readonly ManagerRegistry $doctrine,
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $auditLogger,
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
        $failedCount = 0;

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
                ++$failedCount;

                if ($isExpectedFailure && $isLastAttempt) {
                    // Termination for payment default is owned by the daily
                    // app:terminate-overdue-contracts sweep (spec 078).
                    $io->text(sprintf('  [NO MORE RETRIES] Contract %s failed final attempt %d; app:terminate-overdue-contracts will terminate it.', $contract->id, $contract->failedBillingAttempts));
                } elseif ($isExpectedFailure) {
                    $io->text(sprintf('  [RETRY LATER] Contract %s failed attempt %d, will retry later.', $contract->id, $contract->failedBillingAttempts));
                }
            }
        }

        $io->success(sprintf('Processed: %d success, %d failed.', $successCount, $failedCount));

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

                $this->auditLogger->log(
                    entityType: 'contract',
                    entityId: $contract->id->toRfc4122(),
                    eventType: 'recurring_payment_failed',
                    payload: [
                        'attempt' => $contract->failedBillingAttempts,
                        'reason' => $exception->getMessage(),
                        'is_last_attempt' => $isLastAttempt,
                    ],
                    orderId: $contract->order->id,
                    userIdContext: $contract->user->id,
                );

                // Console commands are outside the doctrine_transaction
                // middleware — this flush must come AFTER the audit persist,
                // or the row would only ever be committed by accident.
                $this->getEntityManager()->flush();

                $this->eventBus->dispatch(new RecurringPaymentFailed(
                    contractId: $contract->id,
                    attempt: $contract->failedBillingAttempts,
                    reason: $exception->getMessage(),
                    occurredOn: $now,
                ));

                // VOP XI: send formal "Výzva k úhradě" after 2nd failure (day 3).
                // The payment deadline mirrors the configurable auto-termination
                // limit: due date + N days (never in the past).
                if (2 === $contract->failedBillingAttempts && null === $contract->paymentDemandSentAt) {
                    $contract->recordPaymentDemandSent($now);

                    $days = $this->settingsRepository->getSettings()->overdueTerminationDays;
                    /** @var \DateTimeImmutable $dueDate */
                    $dueDate = $contract->nextBillingDate; // set while a charge is unpaid
                    $deadline = max($now, $dueDate->modify(sprintf('+%d days', $days)));

                    $this->auditLogger->log(
                        entityType: 'contract',
                        entityId: $contract->id->toRfc4122(),
                        eventType: 'payment_demand_sent',
                        payload: [
                            'attempt' => $contract->failedBillingAttempts,
                            'deadline' => $deadline->format('Y-m-d'),
                        ],
                        orderId: $contract->order->id,
                        userIdContext: $contract->user->id,
                    );

                    // Console commands are outside the doctrine_transaction
                    // middleware — flush AFTER the audit persist so the legal
                    // "payment demand sent" trail is committed even when this
                    // contract is the last one in the run.
                    $this->getEntityManager()->flush();

                    $this->eventBus->dispatch(new PaymentDemandSent(
                        contractId: $contract->id,
                        deadline: $deadline,
                        occurredOn: $now,
                    ));
                }

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
