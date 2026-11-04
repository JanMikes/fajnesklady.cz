<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Command\ProcessBankTransferDebtPaymentCommand;
use App\Command\ProcessBankTransferPaymentCommand;
use App\Entity\BankTransaction;
use App\Entity\BankTransactionAllocation;
use App\Entity\Contract;
use App\Entity\Order;
use App\Enum\AllocationStepType;
use App\Enum\BillingMode;
use App\Repository\BankTransactionAllocationRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\AuditLogger;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The single allocation waterfall, used identically by the FIO auto-matcher and
 * by an admin pairing a transfer by hand (spec 091).
 *
 *   1. onboarding debt      (partial allowed — accumulates across transfers)
 *   2. post-termination contract debt (partial allowed)
 *   3. the current obligation: a manual-billing cycle, or the order's first
 *      payment — all-or-nothing, because half a cycle is not a paid cycle
 *   4. whatever is left becomes contract credit
 *
 * plan() is pure: it reads state and returns what *would* happen, so the admin
 * confirm screen can render precisely what apply() will then execute.
 */
final readonly class PaymentAllocator
{
    public function __construct(
        private BankTransactionAllocationRepository $allocationRepository,
        private BankTransactionRepository $bankTransactionRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private RecurringAmountCalculator $amountCalculator,
        private AuditLogger $auditLogger,
        private ProvideIdentity $identityProvider,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * A card order's first payment is deliberately NOT allocatable by transfer:
     * the recurring mandate only exists if the first charge went through the card
     * gateway, so settling it by wire would create a contract that can never
     * charge. Both the auto-matcher and manual pairing refuse it (spec 091 D1).
     */
    public function isFirstPaymentBlockedForCard(Order $order, ?Contract $contract): bool
    {
        return null === $contract
            && BillingMode::AUTO_RECURRING === $order->billingMode
            && $order->canBePaid();
    }

    /**
     * plan() for re-pairing an existing transaction (admin confirm screen):
     * whatever an earlier allocation of THIS transaction parked as credit on
     * the same contract is its own money, about to be replayed in full — it
     * must not also count as credit available, or the preview shows money
     * twice. {@see \App\Command\PairBankTransactionHandler} takes that credit
     * back before planning, so both arrive at the same plan.
     */
    public function planForTransaction(BankTransaction $transaction, Order $order, ?Contract $contract, \DateTimeImmutable $now): AllocationPlan
    {
        $ownCredit = 0;
        if (null !== $contract) {
            foreach ($this->allocationRepository->findByTransactionAndType($transaction, AllocationStepType::CREDIT) as $creditAllocation) {
                if (null !== $creditAllocation->contract && $creditAllocation->contract->id->equals($contract->id)) {
                    $ownCredit += $creditAllocation->amountInHaler;
                }
            }
        }

        return $this->plan($order, $contract, $transaction->amount, $now, $ownCredit);
    }

    public function plan(Order $order, ?Contract $contract, int $amountInHaler, \DateTimeImmutable $now, int $creditToDisregard = 0): AllocationPlan
    {
        $creditAvailable = null !== $contract ? max(0, $contract->creditBalance - $creditToDisregard) : 0;
        $available = $amountInHaler + $creditAvailable;
        $remaining = $available;

        $steps = [];

        // 1. Onboarding debt. Order.onboardingDebtInHaler is all-or-nothing in the
        // entity (markDebtPaid has no partial form), so "how much is already paid"
        // comes from the recorded allocations instead.
        if ($order->hasUnpaidDebt()) {
            $alreadyPaid = $this->allocationRepository->sumForOrderByType($order, AllocationStepType::ONBOARDING_DEBT);
            $outstanding = max(0, (int) $order->onboardingDebtInHaler - $alreadyPaid);

            if ($outstanding > 0) {
                $allocated = min($remaining, $outstanding);
                $remaining -= $allocated;

                $steps[] = new AllocationStep(
                    type: AllocationStepType::ONBOARDING_DEBT,
                    expected: $outstanding,
                    allocated: $allocated,
                    fullySettled: $allocated === $outstanding,
                    previouslyPaid: $alreadyPaid,
                );
            }
        }

        // 2. Post-termination contract debt. reduceOutstandingDebt() already
        // supports partial settlement, so this one can move in steps.
        if (null !== $contract && $contract->hasOutstandingDebt()) {
            $outstanding = (int) $contract->outstandingDebtAmount;
            $allocated = min($remaining, $outstanding);
            $remaining -= $allocated;

            $steps[] = new AllocationStep(
                type: AllocationStepType::CONTRACT_DEBT,
                expected: $outstanding,
                allocated: $allocated,
                fullySettled: $allocated === $outstanding,
            );
        }

        // 3. The current obligation — all-or-nothing.
        $cycleStep = $this->planCurrentObligation($order, $contract, $remaining, $now);
        if (null !== $cycleStep) {
            $steps[] = $cycleStep;
            $remaining -= $cycleStep->allocated;
        }

        // 4. Anything left becomes credit — but only a contract can hold it.
        $unallocated = 0;
        if ($remaining > 0) {
            if (null !== $contract) {
                $steps[] = new AllocationStep(
                    type: AllocationStepType::CREDIT,
                    expected: $remaining,
                    allocated: $remaining,
                    fullySettled: true,
                );
            } else {
                $unallocated = $remaining;
            }
        }

        return new AllocationPlan(
            steps: $steps,
            available: $available,
            creditUsed: $creditAvailable,
            unallocated: $unallocated,
        );
    }

    private function planCurrentObligation(Order $order, ?Contract $contract, int $remaining, \DateTimeImmutable $now): ?AllocationStep
    {
        if (null !== $contract && $contract->usesManualBillingTrack()) {
            // Settle against what the customer was ASKED to pay for this cycle
            // (the open request's frozen amount), not a fresh recalculation:
            // a prolongation after the request went out re-prices the cycle,
            // and the customer who then paid the requested amount exactly was
            // flagged as under-paying and had the money parked in credit.
            $openRequest = null !== $contract->nextBillingDate
                ? $this->manualPaymentRequestRepository->findUnpaidByContractAndPeriod($contract, $contract->nextBillingDate)
                : null;
            $expected = $this->amountCalculator->calculateForOpenCycle($contract, $now, $openRequest);

            if ($expected <= 0) {
                return null;
            }

            // All-or-nothing: a cycle is either paid or it is not.
            //
            // A shortfall must allocate NOTHING, so the money falls through to the
            // credit balance where the next transfer picks it up. Recording a
            // partial BILLING_CYCLE allocation instead would lose it: unlike
            // ONBOARDING_DEBT and FIRST_PAYMENT, cycle allocations are never summed
            // back (cycles repeat, so a per-order total has no meaning), while
            // apply() would still have drained the credit that fed it.
            $covers = $remaining >= $expected;

            return new AllocationStep(
                type: AllocationStepType::BILLING_CYCLE,
                expected: $expected,
                allocated: $covers ? $expected : 0,
                fullySettled: $covers,
            );
        }

        if (null === $contract && $order->canBePaid() && $order->firstPaymentPrice > 0) {
            if ($this->isFirstPaymentBlockedForCard($order, $contract)) {
                return null;
            }

            // Partial first payments accumulate across transfers (spec 091 D2) —
            // an order has no contract yet, so there is no credit balance to use.
            $alreadyPaid = $this->allocationRepository->sumForOrderByType($order, AllocationStepType::FIRST_PAYMENT);
            $outstanding = max(0, $order->firstPaymentPrice - $alreadyPaid);

            if ($outstanding <= 0) {
                return null;
            }

            $covers = $remaining >= $outstanding;

            return new AllocationStep(
                type: AllocationStepType::FIRST_PAYMENT,
                expected: $outstanding,
                allocated: $covers ? $outstanding : $remaining,
                fullySettled: $covers,
                previouslyPaid: $alreadyPaid,
            );
        }

        return null;
    }

    /**
     * Execute a plan: record what each part of the money was for, move the credit
     * balance, and dispatch the existing payment commands for whatever the plan
     * fully settled.
     *
     * Runs inside a command handler, so the doctrine_transaction middleware owns
     * the flush — nothing here calls flush(), and nothing mutates after a nested
     * dispatch() returns.
     */
    public function apply(
        AllocationPlan $plan,
        BankTransaction $transaction,
        Order $order,
        ?Contract $contract,
        \DateTimeImmutable $now,
    ): void {
        $creditBefore = null !== $contract ? $contract->creditBalance : 0;

        // Draw down the credit the plan counted on before adding anything back,
        // so a plan that consumed credit and then re-credited a surplus nets out.
        if (null !== $contract && $plan->creditUsed > 0) {
            $contract->consumeCredit($plan->creditUsed);
        }

        $settledSteps = [];

        foreach ($plan->steps as $step) {
            if ($step->allocated <= 0) {
                continue;
            }

            $this->allocationRepository->save(new BankTransactionAllocation(
                id: $this->identityProvider->next(),
                bankTransaction: $transaction,
                order: $order,
                type: $step->type,
                amountInHaler: $step->allocated,
                createdAt: $now,
                contract: $contract,
            ));

            // Credit is recorded as an allocation row too, so a later re-pair of
            // this transaction knows how much of the contract's credit balance
            // came from it and can take it back before replaying the money
            // ({@see \App\Command\PairBankTransactionHandler}).
            if (AllocationStepType::CREDIT === $step->type) {
                $contract?->addCredit($step->allocated);

                continue;
            }

            if (AllocationStepType::CONTRACT_DEBT === $step->type && null !== $contract) {
                // Clamped in plan(), but reduceOutstandingDebt() throws on
                // over-reduction so keep the guard next to the call.
                $contract->reduceOutstandingDebt(min($step->allocated, (int) $contract->outstandingDebtAmount));
            }

            if ($step->fullySettled) {
                $settledSteps[] = $step;
            }
        }

        // Earlier transfers that under-paid carry an `amount_mismatch` badge, which
        // drives a customer-facing "the amount does not match, please contact us"
        // banner. Once this plan settles everything, those transfers are no longer
        // outstanding and must stop being flagged — otherwise a customer who
        // eventually paid in full keeps seeing the warning forever.
        if ([] !== $settledSteps && $plan->settlesEverything()) {
            foreach ($this->bankTransactionRepository->findAmountMismatchByOrder($order) as $stale) {
                if ($stale->id->equals($transaction->id)) {
                    continue;
                }

                $stale->promoteToMatched($now);
            }
        }

        $this->auditLogger->log(
            entityType: 'bank_transaction',
            entityId: $transaction->id->toRfc4122(),
            eventType: 'allocated',
            payload: [
                'order_id' => $order->id->toRfc4122(),
                'incoming_amount' => $transaction->amount,
                'available' => $plan->available,
                'credit_before' => $creditBefore,
                'credit_after' => null !== $contract ? $contract->creditBalance : 0,
                'unallocated' => $plan->unallocated,
                'settles_everything' => $plan->settlesEverything(),
                'steps' => array_map(static fn (AllocationStep $s): array => [
                    'type' => $s->type->value,
                    'expected' => $s->expected,
                    'allocated' => $s->allocated,
                    'previously_paid' => $s->previouslyPaid,
                    'fully_settled' => $s->fullySettled,
                ], $plan->steps),
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );

        // Dispatch last: the commands below run their own handlers, and mutating
        // our own entities after a nested dispatch risks losing the write.
        foreach ($settledSteps as $step) {
            match ($step->type) {
                AllocationStepType::ONBOARDING_DEBT => $this->commandBus->dispatch(
                    new ProcessBankTransferDebtPaymentCommand($transaction, $order),
                ),
                // The recorded Payment must be the WHOLE obligation, not this
                // transfer's share of it. $step->expected is the outstanding
                // remainder, so on a first payment completed by a second transfer
                // it would book 1 100 Kč for a 3 100 Kč rental. A billing cycle has
                // no such split — calculate() always returns the full cycle price.
                AllocationStepType::FIRST_PAYMENT => $this->commandBus->dispatch(
                    new ProcessBankTransferPaymentCommand($transaction, $order, $order->firstPaymentPrice),
                ),
                AllocationStepType::BILLING_CYCLE => $this->commandBus->dispatch(
                    new ProcessBankTransferPaymentCommand($transaction, $order, $step->expected),
                ),
                // Contract debt is settled inline above; there is no command for it.
                default => null,
            };
        }
    }
}
