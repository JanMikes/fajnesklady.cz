<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BankAccountMapping;
use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\BankAccountMappingRepository;
use App\Repository\BankTransactionAllocationRepository;
use App\Repository\ContractRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\Payment\AllocationStep;
use App\Service\Payment\PaymentAllocator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PairBankTransactionHandler
{
    /** Distinguishes an admin decision from the auto-matcher's own methods. */
    public const string MATCH_METHOD = 'manual';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContractRepository $contractRepository,
        private BankAccountMappingRepository $bankAccountMappingRepository,
        private BankTransactionAllocationRepository $allocationRepository,
        private PaymentAllocator $paymentAllocator,
        private AuditLogger $auditLogger,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PairBankTransactionCommand $command): void
    {
        $transaction = $this->entityManager->find(BankTransaction::class, $command->transactionId);
        if (null === $transaction) {
            throw new \DomainException('Bank transaction not found.');
        }

        $order = $this->entityManager->find(Order::class, $command->orderId);
        if (null === $order) {
            throw new \DomainException('Order not found.');
        }

        $admin = $this->entityManager->find(User::class, $command->adminId);
        if (null === $admin) {
            throw new \DomainException('Admin user not found.');
        }

        if (!$transaction->isUnmatched() && !$transaction->isAmountMismatch()) {
            throw new \DomainException(sprintf('Only unmatched or partially settled transactions can be paired, got "%s".', $transaction->status->value));
        }

        $now = $this->clock->now();
        $contract = $this->contractRepository->findByOrder($order);

        // An amount_mismatch row already points at an order. Re-pointing it at a
        // different one has to release the old link first, or the row would carry
        // a stale expected amount from an obligation it no longer belongs to.
        if (null !== $transaction->pairedOrder && !$transaction->pairedOrder->id->equals($order->id)) {
            $transaction->clearPairing();
        }

        // Release whatever this transaction was previously allocated to before
        // re-planning. We are about to replay its FULL amount, so leaving the old
        // rows in place would either count the same money twice against the same
        // order, or leave the previous order credited with money that has just
        // been reassigned elsewhere.
        $this->allocationRepository->deleteForTransaction($transaction);

        $plan = $this->paymentAllocator->plan($order, $contract, $transaction->amount, $now);

        // Spec 091 D1: a card order's first charge is the only way to obtain the
        // recurring mandate, so settling it by wire would leave a contract that can
        // never charge — the allocator refuses that step outright.
        //
        // The guard is on the PLAN, not on the order, and deliberately mirrors the
        // auto-matcher: a card order may still owe an onboarding debt, and paying
        // that by wire is exactly what spec 089 exists to allow. Only refuse when
        // the money has nowhere legitimate to go.
        if ([] === $plan->obligationSteps() && 0 === $plan->creditAdded()) {
            throw new \DomainException($this->paymentAllocator->isFirstPaymentBlockedForCard($order, $contract) ? sprintf('Order %s is paid by card: its first payment cannot be settled by bank transfer.', $order->id->toRfc4122()) : sprintf('Order %s has no debt, cycle or payable first payment for this transfer to settle.', $order->id->toRfc4122()));
        }

        $this->paymentAllocator->apply($plan, $transaction, $order, $contract, $now);

        $transaction->pairToOrder($order, self::MATCH_METHOD, $admin, $now);

        $this->auditLogger->log(
            entityType: 'bank_transaction',
            entityId: $command->transactionId->toRfc4122(),
            eventType: 'manually_paired',
            payload: [
                'fio_transaction_id' => $transaction->fioTransactionId,
                'amount' => $transaction->amount,
                'variable_symbol' => $transaction->variableSymbol,
                'sender_account' => $transaction->senderAccountNumber,
                'order_id' => $order->id->toRfc4122(),
                'admin_id' => $admin->id->toRfc4122(),
                'note' => $command->note,
                'remember_sender_account' => $command->rememberSenderAccount,
                'settles_everything' => $plan->settlesEverything(),
                'credit_added' => $plan->creditAdded(),
                'unallocated' => $plan->unallocated,
                'steps' => array_map(static fn (AllocationStep $step): array => [
                    'type' => $step->type->value,
                    'expected' => $step->expected,
                    'allocated' => $step->allocated,
                    'previously_paid' => $step->previouslyPaid,
                    'fully_settled' => $step->fullySettled,
                ], $plan->steps),
            ],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );

        $this->rememberSenderAccount($command, $transaction, $order, $admin, $now);
    }

    /**
     * Register the payer's account so the matcher recognises it next time.
     * Silently skipped when there is no account to remember or the pair is
     * already registered — the (accountNumber, order) unique constraint would
     * otherwise blow up the whole pairing on a repeat payer.
     */
    private function rememberSenderAccount(
        PairBankTransactionCommand $command,
        BankTransaction $transaction,
        Order $order,
        User $admin,
        \DateTimeImmutable $now,
    ): void {
        if (!$command->rememberSenderAccount) {
            return;
        }

        $accountNumber = $transaction->senderAccountNumber;
        if (null === $accountNumber || '' === $accountNumber) {
            return;
        }

        if ($this->bankAccountMappingRepository->existsForAccountAndOrder($accountNumber, $order)) {
            return;
        }

        $this->bankAccountMappingRepository->save(new BankAccountMapping(
            id: $this->identityProvider->next(),
            accountNumber: $accountNumber,
            user: $order->user,
            order: $order,
            createdBy: $admin,
            createdAt: $now,
        ));
    }
}
