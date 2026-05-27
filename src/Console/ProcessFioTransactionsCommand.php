<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\ProcessIncomingBankTransactionCommand;
use App\Repository\BankTransactionRepository;
use App\Service\AuditLogger;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\Payment\FioClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use FioApi\Exceptions\TooGreedyException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:process-fio-transactions',
    description: 'Poll FIO banka API and auto-match incoming bank transfer payments',
)]
final class ProcessFioTransactionsCommand extends Command
{
    public function __construct(
        private readonly FioClient $fioClient,
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $startTime = microtime(true);

        $from = $now->modify('-3 days');
        $to = $now;

        $stats = [
            'fetched' => 0,
            'processed' => 0,
            'failed' => 0,
            'skipped_duplicate' => 0,
        ];

        try {
            $transactions = $this->fioClient->downloadTransactions($from, $to);
        } catch (TooGreedyException $e) {
            $this->logger->warning('FIO API rate limit hit — will retry on next cron run', [
                'exception' => $e,
            ]);

            $this->auditLogger->log(
                entityType: 'system',
                entityId: 'fio_cron',
                eventType: 'fio_cron_failed',
                payload: [
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'was_rate_limited' => true,
                ],
            );

            // Console command — no messenger middleware
            $this->entityManager->flush();

            $io->warning('FIO API rate limited. Will retry next run.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('FIO API call failed', [
                'exception' => $e,
            ]);

            $this->auditLogger->log(
                entityType: 'system',
                entityId: 'fio_cron',
                eventType: 'fio_cron_failed',
                payload: [
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'was_rate_limited' => false,
                ],
            );

            // Console command — no messenger middleware
            $this->entityManager->flush();

            $io->error('FIO API call failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        foreach ($transactions as $fioTx) {
            if ($fioTx->amount <= 0) {
                continue;
            }

            if ('CZK' !== $fioTx->currency) {
                continue;
            }

            ++$stats['fetched'];

            if ($this->bankTransactionRepository->existsByFioTransactionId($fioTx->id)) {
                ++$stats['skipped_duplicate'];

                continue;
            }

            try {
                $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand($fioTx));
                ++$stats['processed'];
            } catch (\Throwable $rawException) {
                ++$stats['failed'];
                $exception = HandlerFailureUnwrap::unwrap($rawException);

                $this->logger->error('Failed to process incoming bank transaction', [
                    'fio_transaction_id' => $fioTx->id,
                    'exception' => $exception,
                ]);

                $this->resetEntityManagerIfNeeded();
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditLogger->log(
            entityType: 'system',
            entityId: 'fio_cron',
            eventType: 'fio_cron_completed',
            payload: [
                ...$stats,
                'date_range_from' => $from->format('Y-m-d'),
                'date_range_to' => $to->format('Y-m-d'),
                'duration_ms' => $durationMs,
            ],
        );

        // Console command — no messenger middleware
        $this->entityManager->flush();

        $io->success(sprintf(
            'FIO cron: %d fetched, %d processed, %d failed, %d duplicates skipped.',
            $stats['fetched'],
            $stats['processed'],
            $stats['failed'],
            $stats['skipped_duplicate'],
        ));

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resetEntityManagerIfNeeded(): void
    {
        $manager = $this->doctrine->getManager();

        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->doctrine->resetManager();
        }
    }
}
