<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Event\BankTransferAmountMismatch;
use App\Repository\BankAccountMappingRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\FineRepository;
use App\Repository\OrderRepository;
use App\Service\AuditLogger;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Identity\ProvideIdentity;
use App\Service\Messenger\HandlerFailureUnwrap;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ProcessIncomingBankTransactionHandler
{
    public function __construct(
        private BankTransactionRepository $bankTransactionRepository,
        private OrderRepository $orderRepository,
        private ContractRepository $contractRepository,
        private BankAccountMappingRepository $bankAccountMappingRepository,
        private FineRepository $fineRepository,
        private RecurringAmountCalculator $amountCalculator,
        private AuditLogger $auditLogger,
        private ProvideIdentity $identityProvider,
        private MessageBusInterface $commandBus,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessIncomingBankTransactionCommand $command): void
    {
        $fioTx = $command->fioTransaction;
        $now = $this->clock->now();

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

        $this->attemptAutoMatch($bankTx, $now);
    }

    private function attemptAutoMatch(BankTransaction $bankTx, \DateTimeImmutable $now): void
    {
        if (null !== $bankTx->variableSymbol && '' !== $bankTx->variableSymbol) {
            $order = $this->orderRepository->findByVariableSymbol($bankTx->variableSymbol);

            if (null !== $order) {
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
                        orderId: $order->id,
                        userIdContext: $order->user->id,
                    );
                }

                $this->matchToOrder($bankTx, $order, 'variable_symbol', $now);

                return;
            }

            $fine = $this->fineRepository->findByVariableSymbol($bankTx->variableSymbol);
            if (null !== $fine && $fine->isPayable()) {
                if ($bankTx->amount === $fine->amountInHaler) {
                    $fine->markPaid($now);
                    $bankTx->pairToContract($fine->contract, 'variable_symbol_fine', null, $now);

                    $this->auditLogger->log(
                        entityType: 'bank_transaction',
                        entityId: $bankTx->id->toRfc4122(),
                        eventType: 'auto_matched_to_fine',
                        payload: [
                            'fine_id' => $fine->id->toRfc4122(),
                            'variable_symbol' => $bankTx->variableSymbol,
                            'expected_amount' => $fine->amountInHaler,
                            'received_amount' => $bankTx->amount,
                        ],
                        orderId: $fine->contract->order->id,
                        userIdContext: $fine->user->id,
                    );
                } else {
                    $bankTx->markAmountMismatchContract($fine->contract, 'variable_symbol_fine', $fine->amountInHaler, $now);

                    $this->auditLogger->log(
                        entityType: 'bank_transaction',
                        entityId: $bankTx->id->toRfc4122(),
                        eventType: 'amount_mismatch',
                        payload: [
                            'fine_id' => $fine->id->toRfc4122(),
                            'expected_amount' => $fine->amountInHaler,
                            'received_amount' => $bankTx->amount,
                            'difference' => $bankTx->amount - $fine->amountInHaler,
                            'variable_symbol' => $bankTx->variableSymbol,
                            'type' => 'fine_payment',
                        ],
                        orderId: $fine->contract->order->id,
                        userIdContext: $fine->user->id,
                    );
                }

                return;
            }
        }

        if (null !== $bankTx->senderAccountNumber && '' !== $bankTx->senderAccountNumber) {
            $mapping = $this->bankAccountMappingRepository->findByAccountNumber($bankTx->senderAccountNumber);

            if (null !== $mapping) {
                $order = $mapping->order;
                $contract = $this->contractRepository->findByOrder($order);

                if (null !== $contract && BillingMode::MANUAL_RECURRING === $contract->billingMode) {
                    $expectedAmount = $this->amountCalculator->calculate($contract, $now);

                    if ($bankTx->amount !== $expectedAmount) {
                        $bankTx->markAmountMismatchContract($contract, 'account_mapping', $expectedAmount, $now);

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
                            orderId: $order->id,
                            userIdContext: $order->user->id,
                        );

                        $this->eventBus->dispatch(BankTransferAmountMismatch::forContract(
                            bankTransactionId: $bankTx->id,
                            contractId: $contract->id,
                            orderId: $order->id,
                            expectedAmount: $expectedAmount,
                            receivedAmount: $bankTx->amount,
                            variableSymbol: $bankTx->variableSymbol,
                            occurredOn: $now,
                        ));

                        return;
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
                        orderId: $order->id,
                        userIdContext: $order->user->id,
                    );

                    $this->dispatchPaymentCommand(new ProcessBankTransferPaymentCommand($bankTx, $order));

                    return;
                }

                $this->matchToOrder($bankTx, $order, 'account_mapping', $now);
            }
        }
    }

    private function matchToOrder(
        BankTransaction $bankTx,
        Order $order,
        string $matchMethod,
        \DateTimeImmutable $now,
    ): void {
        $contract = $this->contractRepository->findByOrder($order);

        if (null !== $contract && BillingMode::MANUAL_RECURRING === $contract->billingMode
            && PaymentMethod::BANK_TRANSFER === $order->paymentMethod) {
            $expectedAmount = $this->amountCalculator->calculate($contract, $now);

            if ($bankTx->amount !== $expectedAmount) {
                $bankTx->markAmountMismatchContract($contract, $matchMethod, $expectedAmount, $now);

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
                    orderId: $order->id,
                    userIdContext: $order->user->id,
                );

                $this->eventBus->dispatch(BankTransferAmountMismatch::forContract(
                    bankTransactionId: $bankTx->id,
                    contractId: $contract->id,
                    orderId: $order->id,
                    expectedAmount: $expectedAmount,
                    receivedAmount: $bankTx->amount,
                    variableSymbol: $bankTx->variableSymbol,
                    occurredOn: $now,
                ));

                return;
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
                orderId: $order->id,
                userIdContext: $order->user->id,
            );

            $this->dispatchPaymentCommand(new ProcessBankTransferPaymentCommand($bankTx, $order));

            return;
        }

        if ($order->hasUnpaidDebt()) {
            $debtAmount = (int) $order->onboardingDebtInHaler;

            if ($bankTx->amount !== $debtAmount) {
                $bankTx->markAmountMismatch($order, $matchMethod, $debtAmount, $now);

                $this->auditLogger->log(
                    entityType: 'bank_transaction',
                    entityId: $bankTx->id->toRfc4122(),
                    eventType: 'amount_mismatch',
                    payload: [
                        'order_id' => $order->id->toRfc4122(),
                        'expected_amount' => $debtAmount,
                        'received_amount' => $bankTx->amount,
                        'difference' => $bankTx->amount - $debtAmount,
                        'variable_symbol' => $bankTx->variableSymbol,
                        'type' => 'debt_payment',
                    ],
                    orderId: $order->id,
                    userIdContext: $order->user->id,
                );

                $this->eventBus->dispatch(BankTransferAmountMismatch::forOrder(
                    bankTransactionId: $bankTx->id,
                    orderId: $order->id,
                    expectedAmount: $debtAmount,
                    receivedAmount: $bankTx->amount,
                    variableSymbol: $bankTx->variableSymbol,
                    occurredOn: $now,
                ));

                return;
            }

            $bankTx->pairToOrder($order, $matchMethod, null, $now);

            $this->auditLogger->log(
                entityType: 'bank_transaction',
                entityId: $bankTx->id->toRfc4122(),
                eventType: 'auto_matched_to_order_debt',
                payload: [
                    'order_id' => $order->id->toRfc4122(),
                    'variable_symbol' => $bankTx->variableSymbol,
                    'expected_amount' => $order->onboardingDebtInHaler,
                    'received_amount' => $bankTx->amount,
                    'match_method' => $matchMethod,
                ],
                orderId: $order->id,
                userIdContext: $order->user->id,
            );

            $this->dispatchPaymentCommand(new ProcessBankTransferDebtPaymentCommand($bankTx, $order));

            return;
        }

        if ($order->canBePaid()) {
            if ($bankTx->amount !== $order->firstPaymentPrice) {
                $bankTx->markAmountMismatch($order, $matchMethod, $order->firstPaymentPrice, $now);

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
                    orderId: $order->id,
                    userIdContext: $order->user->id,
                );

                $this->eventBus->dispatch(BankTransferAmountMismatch::forOrder(
                    bankTransactionId: $bankTx->id,
                    orderId: $order->id,
                    expectedAmount: $order->firstPaymentPrice,
                    receivedAmount: $bankTx->amount,
                    variableSymbol: $bankTx->variableSymbol,
                    occurredOn: $now,
                ));

                return;
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
                orderId: $order->id,
                userIdContext: $order->user->id,
            );

            $this->dispatchPaymentCommand(new ProcessBankTransferPaymentCommand($bankTx, $order));
        }
    }

    private function dispatchPaymentCommand(object $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $rawException) {
            $exception = HandlerFailureUnwrap::unwrap($rawException);

            $this->logger->error('Failed to process bank transfer payment', [
                'command' => $command::class,
                'exception' => $exception,
            ]);
        }
    }
}
