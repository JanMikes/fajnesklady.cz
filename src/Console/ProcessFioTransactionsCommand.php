<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\ProcessBankTransferPaymentCommand;
use App\Entity\BankTransaction;
use App\Entity\Contract;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Repository\BankAccountMappingRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Service\AuditLogger;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Identity\ProvideIdentity;
use App\Service\Payment\FioClient;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly BankAccountMappingRepository $bankAccountMappingRepository,
        private readonly RecurringAmountCalculator $amountCalculator,
        private readonly AuditLogger $auditLogger,
        private readonly ProvideIdentity $identityProvider,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
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
            'transactions_fetched' => 0,
            'transactions_matched' => 0,
            'transactions_unmatched' => 0,
            'transactions_skipped_duplicate' => 0,
            'amount_mismatches' => 0,
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

            ++$stats['transactions_fetched'];

            if ($this->bankTransactionRepository->existsByFioTransactionId($fioTx->id)) {
                ++$stats['transactions_skipped_duplicate'];

                continue;
            }

            $bankTx = new BankTransaction(
                id: $this->identityProvider->next(),
                fioTransactionId: $fioTx->id,
                amount: $fioTx->amount,
                currency: $fioTx->currency,
                variableSymbol: $fioTx->variableSymbol,
                senderAccountNumber: $fioTx->senderAccountNumber,
                senderName: $fioTx->senderName,
                transactionDate: $fioTx->date,
                comment: $fioTx->comment,
                createdAt: $now,
            );

            $this->bankTransactionRepository->save($bankTx);

            $this->auditLogger->log(
                entityType: 'bank_transaction',
                entityId: $bankTx->id->toRfc4122(),
                eventType: 'received',
                payload: [
                    'fio_transaction_id' => $fioTx->id,
                    'amount' => $fioTx->amount,
                    'currency' => $fioTx->currency,
                    'variable_symbol' => $fioTx->variableSymbol,
                    'sender_account' => $fioTx->senderAccountNumber,
                    'sender_name' => $fioTx->senderName,
                    'transaction_date' => $fioTx->date->format('Y-m-d'),
                ],
            );

            $matched = $this->attemptAutoMatch($bankTx, $now, $stats);

            if (!$matched) {
                ++$stats['transactions_unmatched'];
            } else {
                ++$stats['transactions_matched'];
            }

            // Flush after each transaction to preserve work
            // Console commands run outside messenger middleware
            $this->entityManager->flush();
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

        // Flush the final audit log entry
        $this->entityManager->flush();

        $io->success(sprintf(
            'FIO cron: %d fetched, %d matched, %d unmatched, %d duplicates skipped, %d mismatches.',
            $stats['transactions_fetched'],
            $stats['transactions_matched'],
            $stats['transactions_unmatched'],
            $stats['transactions_skipped_duplicate'],
            $stats['amount_mismatches'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $stats
     */
    private function attemptAutoMatch(BankTransaction $bankTx, \DateTimeImmutable $now, array &$stats): bool
    {
        // 1. Variable symbol match (strongest)
        if (null !== $bankTx->variableSymbol && '' !== $bankTx->variableSymbol) {
            $order = $this->orderRepository->findByVariableSymbol($bankTx->variableSymbol);

            if (null !== $order) {
                // Check if an account mapping exists for a DIFFERENT order
                $accountMapping = null !== $bankTx->senderAccountNumber
                    ? $this->bankAccountMappingRepository->findByAccountNumber($bankTx->senderAccountNumber)
                    : null;

                if (null !== $accountMapping && !$accountMapping->order->id->equals($order->id)) {
                    $this->auditLogger->log(
                        entityType: 'bank_transaction',
                        entityId: $bankTx->id->toRfc4122(),
                        eventType: 'vs_override_account_mapping',
                        payload: [
                            'vs_matched_order_id' => $order->id->toRfc4122(),
                            'account_mapping_order_id' => $accountMapping->order->id->toRfc4122(),
                            'variable_symbol' => $bankTx->variableSymbol,
                            'sender_account' => $bankTx->senderAccountNumber,
                        ],
                    );
                }

                return $this->matchToOrder($bankTx, $order, 'variable_symbol', $now, $stats);
            }
        }

        // 2. BankAccountMapping match (fallback)
        if (null !== $bankTx->senderAccountNumber && '' !== $bankTx->senderAccountNumber) {
            $mapping = $this->bankAccountMappingRepository->findByAccountNumber($bankTx->senderAccountNumber);

            if (null !== $mapping) {
                $order = $mapping->order;
                $contract = $this->contractRepository->findByOrder($order);

                if (null !== $contract && BillingMode::MANUAL_RECURRING === $contract->billingMode) {
                    $expectedAmount = $this->amountCalculator->calculate($contract, $now);

                    if ($bankTx->amount !== $expectedAmount) {
                        $bankTx->markAmountMismatchContract($contract, 'account_mapping', $now);
                        ++$stats['amount_mismatches'];

                        $this->auditLogger->log(
                            entityType: 'bank_transaction',
                            entityId: $bankTx->id->toRfc4122(),
                            eventType: 'amount_mismatch',
                            payload: [
                                'contract_id' => $contract->id->toRfc4122(),
                                'expected_amount' => $expectedAmount,
                                'received_amount' => $bankTx->amount,
                                'difference' => $bankTx->amount - $expectedAmount,
                                'variable_symbol' => $bankTx->variableSymbol,
                            ],
                        );

                        return true;
                    }

                    $bankTx->pairToContract($contract, 'account_mapping', null, $now);

                    $this->auditLogger->log(
                        entityType: 'bank_transaction',
                        entityId: $bankTx->id->toRfc4122(),
                        eventType: 'auto_matched_via_account',
                        payload: [
                            'order_id' => $order->id->toRfc4122(),
                            'contract_id' => $contract->id->toRfc4122(),
                            'sender_account' => $bankTx->senderAccountNumber,
                            'mapping_id' => $mapping->id->toRfc4122(),
                            'expected_amount' => $expectedAmount,
                            'received_amount' => $bankTx->amount,
                        ],
                    );

                    try {
                        $this->commandBus->dispatch(new ProcessBankTransferPaymentCommand($bankTx, $order));
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to process account-mapped bank transfer payment', [
                            'bank_transaction_id' => $bankTx->id->toRfc4122(),
                            'order_id' => $order->id->toRfc4122(),
                            'exception' => $e,
                        ]);
                    }

                    return true;
                }

                return $this->matchToOrder($bankTx, $order, 'account_mapping', $now, $stats);
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $stats
     */
    private function matchToOrder(
        BankTransaction $bankTx,
        \App\Entity\Order $order,
        string $matchMethod,
        \DateTimeImmutable $now,
        array &$stats,
    ): bool {
        $contract = $this->contractRepository->findByOrder($order);

        // Recurring contract match
        if (null !== $contract && BillingMode::MANUAL_RECURRING === $contract->billingMode
            && PaymentMethod::BANK_TRANSFER === $order->paymentMethod) {
            $expectedAmount = $this->amountCalculator->calculate($contract, $now);

            if ($bankTx->amount !== $expectedAmount) {
                $bankTx->markAmountMismatchContract($contract, $matchMethod, $now);
                ++$stats['amount_mismatches'];

                $this->auditLogger->log(
                    entityType: 'bank_transaction',
                    entityId: $bankTx->id->toRfc4122(),
                    eventType: 'amount_mismatch',
                    payload: [
                        'contract_id' => $contract->id->toRfc4122(),
                        'expected_amount' => $expectedAmount,
                        'received_amount' => $bankTx->amount,
                        'difference' => $bankTx->amount - $expectedAmount,
                        'variable_symbol' => $bankTx->variableSymbol,
                    ],
                );

                return true;
            }

            $bankTx->pairToContract($contract, $matchMethod, null, $now);

            $this->auditLogger->log(
                entityType: 'bank_transaction',
                entityId: $bankTx->id->toRfc4122(),
                eventType: 'auto_matched_to_contract',
                payload: [
                    'contract_id' => $contract->id->toRfc4122(),
                    'order_id' => $order->id->toRfc4122(),
                    'variable_symbol' => $bankTx->variableSymbol,
                    'expected_amount' => $expectedAmount,
                    'received_amount' => $bankTx->amount,
                    'billing_period_start' => ($contract->nextBillingDate ?? $now)->format('Y-m-d'),
                ],
            );

            try {
                $this->commandBus->dispatch(new ProcessBankTransferPaymentCommand($bankTx, $order));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process recurring bank transfer payment', [
                    'bank_transaction_id' => $bankTx->id->toRfc4122(),
                    'contract_id' => $contract->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }

            return true;
        }

        // First payment match
        if ($order->canBePaid()) {
            if ($bankTx->amount !== $order->firstPaymentPrice) {
                $bankTx->markAmountMismatch($order, $matchMethod, $now);
                ++$stats['amount_mismatches'];

                $this->auditLogger->log(
                    entityType: 'bank_transaction',
                    entityId: $bankTx->id->toRfc4122(),
                    eventType: 'amount_mismatch',
                    payload: [
                        'order_id' => $order->id->toRfc4122(),
                        'expected_amount' => $order->firstPaymentPrice,
                        'received_amount' => $bankTx->amount,
                        'difference' => $bankTx->amount - $order->firstPaymentPrice,
                        'variable_symbol' => $bankTx->variableSymbol,
                    ],
                );

                return true;
            }

            $bankTx->pairToOrder($order, $matchMethod, null, $now);

            $this->auditLogger->log(
                entityType: 'bank_transaction',
                entityId: $bankTx->id->toRfc4122(),
                eventType: 'auto_matched_to_order',
                payload: [
                    'order_id' => $order->id->toRfc4122(),
                    'variable_symbol' => $bankTx->variableSymbol,
                    'expected_amount' => $order->firstPaymentPrice,
                    'received_amount' => $bankTx->amount,
                    'match_method' => $matchMethod,
                ],
            );

            try {
                $this->commandBus->dispatch(new ProcessBankTransferPaymentCommand($bankTx, $order));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process first bank transfer payment', [
                    'bank_transaction_id' => $bankTx->id->toRfc4122(),
                    'order_id' => $order->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }

            return true;
        }

        return false;
    }
}
