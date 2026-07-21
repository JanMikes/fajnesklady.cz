<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BankTransaction;
use App\Entity\Contract;
use App\Entity\Order;
use App\Event\BankTransferAmountMismatch;
use App\Repository\BankAccountMappingRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\FineRepository;
use App\Repository\OrderRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\Payment\AllocationPlan;
use App\Service\Payment\AllocationStep;
use App\Service\Payment\PaymentAllocator;
use Psr\Clock\ClockInterface;
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
        private PaymentAllocator $paymentAllocator,
        private AuditLogger $auditLogger,
        private ProvideIdentity $identityProvider,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
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
                $accountMappings = null !== $bankTx->senderAccountNumber
                    ? $this->bankAccountMappingRepository->findAllByAccountNumber($bankTx->senderAccountNumber)
                    : [];

                // The variable symbol is the stronger signal and wins, but record
                // it when the payer's registered account points somewhere else.
                $conflicting = array_values(array_filter(
                    $accountMappings,
                    static fn ($mapping): bool => !$mapping->order->id->equals($order->id),
                ));

                if ([] !== $conflicting) {
                    $this->auditLogger->log(
                        entityType: 'bank_transaction',
                        entityId: $bankTx->id->toRfc4122(),
                        eventType: 'vs_override_account_mapping',
                        payload: [
                            'vs_matched_order_id' => $order->id->toRfc4122(),
                            'account_mapping_order_ids' => array_map(
                                static fn ($mapping): string => $mapping->order->id->toRfc4122(),
                                $conflicting,
                            ),
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
            $mappings = $this->bankAccountMappingRepository->findAllByAccountNumber($bankTx->senderAccountNumber);

            // Exactly one mapping means we know where this payer's money goes.
            // Two or more means the payer funds several orders and we cannot know
            // which this transfer is for — leave it for a human rather than
            // guessing, which is what the old setMaxResults(1)-with-no-ORDER-BY
            // lookup did (spec 091 requirement 6).
            if (count($mappings) > 1) {
                $this->auditLogger->log(
                    entityType: 'bank_transaction',
                    entityId: $bankTx->id->toRfc4122(),
                    eventType: 'account_mapping_ambiguous',
                    payload: [
                        'sender_account' => $bankTx->senderAccountNumber,
                        'mapping_count' => count($mappings),
                        'order_ids' => array_map(
                            static fn ($m): string => $m->order->id->toRfc4122(),
                            $mappings,
                        ),
                    ],
                );

                return;
            }

            if (1 === count($mappings)) {
                $this->matchToOrder($bankTx, $mappings[0]->order, 'account_mapping', $now);
            }
        }
    }

    /**
     * Run the transfer through the single allocation waterfall (spec 091):
     * onboarding debt → post-termination contract debt → the current obligation
     * (a manual-billing cycle, or the order's first payment) → credit.
     *
     * Replaces the old hand-rolled cascade, which returned unconditionally from
     * the manual-billing branch and so could never reach the debt branch for a
     * customer who had both — every transfer went to the rental while the debt
     * was never touched. It also replaces tryAccumulatePartialPayments(), whose
     * undifferentiated per-order sum of `amount_mismatch` rows let debt money be
     * counted a second time against the first payment.
     */
    private function matchToOrder(
        BankTransaction $bankTx,
        Order $order,
        string $matchMethod,
        \DateTimeImmutable $now,
    ): void {
        $contract = $this->contractRepository->findByOrder($order);

        $plan = $this->paymentAllocator->plan($order, $contract, $bankTx->amount, $now);

        // The allocator itself refuses to put money toward a card order's first
        // payment (spec 091 D1) — the recurring mandate only exists if that charge
        // went through the card gateway. Note this is NOT an early return: a card
        // order may still carry an onboarding debt, and settling that by wire is
        // exactly what spec 089 exists to allow. Only a plan that reached nothing
        // at all is left for an admin.
        if ([] === $plan->obligationSteps() && 0 === $plan->creditAdded()) {
            $blockedForCard = $this->paymentAllocator->isFirstPaymentBlockedForCard($order, $contract);

            $this->auditLogger->log(
                entityType: 'bank_transaction',
                entityId: $bankTx->id->toRfc4122(),
                eventType: $blockedForCard ? 'auto_match_declined_card_order' : 'auto_match_no_obligation',
                payload: [
                    'order_id' => $order->id->toRfc4122(),
                    'variable_symbol' => $bankTx->variableSymbol,
                    'amount' => $bankTx->amount,
                    'reason' => $blockedForCard
                        ? 'first payment of an auto-recurring card order cannot be settled by bank transfer'
                        : 'no debt, cycle or payable first payment for this order',
                ],
                orderId: $order->id,
                userIdContext: $order->user->id,
            );

            return;
        }

        // We know whose money this is, so the row is paired either way. Status
        // records whether this transfer alone finished what it was applied to:
        // a shortfall stays `amount_mismatch` so it keeps its admin badge.
        $unsettled = $this->firstUnsettledStep($plan);

        if (null === $unsettled) {
            if (null !== $contract) {
                $bankTx->pairToContract($contract, $matchMethod, null, $now);
            } else {
                $bankTx->pairToOrder($order, $matchMethod, null, $now);
            }
        } elseif (null !== $contract) {
            $bankTx->markAmountMismatchContract($contract, $matchMethod, $unsettled->expected, $now);
        } else {
            $bankTx->markAmountMismatch($order, $matchMethod, $unsettled->expected, $now);
        }

        $this->paymentAllocator->apply($plan, $bankTx, $order, $contract, $now);

        if (null !== $unsettled) {
            $this->eventBus->dispatch(null !== $contract
                ? BankTransferAmountMismatch::forContract(
                    bankTransactionId: $bankTx->id,
                    contractId: $contract->id,
                    orderId: $order->id,
                    expectedAmount: $unsettled->expected,
                    receivedAmount: $bankTx->amount,
                    variableSymbol: $bankTx->variableSymbol,
                    occurredOn: $now,
                )
                : BankTransferAmountMismatch::forOrder(
                    bankTransactionId: $bankTx->id,
                    orderId: $order->id,
                    expectedAmount: $unsettled->expected,
                    receivedAmount: $bankTx->amount,
                    variableSymbol: $bankTx->variableSymbol,
                    occurredOn: $now,
                ));
        }
    }

    /**
     * The first obligation the plan could not fully cover — what the admin badge
     * and the mismatch e-mail should talk about.
     */
    private function firstUnsettledStep(AllocationPlan $plan): ?AllocationStep
    {
        foreach ($plan->obligationSteps() as $step) {
            if (!$step->fullySettled) {
                return $step;
            }
        }

        return null;
    }
}
