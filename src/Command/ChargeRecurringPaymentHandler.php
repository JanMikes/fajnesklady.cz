<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Event\RecurringPaymentCharged;
use App\Service\GoPay\GoPayClient;
use App\Service\GoPay\GoPayException;
use App\Service\GoPay\PaymentNotConfirmedException;
use App\Value\GoPayPayment;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ChargeRecurringPaymentHandler
{
    private const int DAYS_PER_MONTH = 30;
    private const int MAX_STATUS_POLLS = 5;
    private const int POLL_INTERVAL_MICROSECONDS = 2_000_000; // 2 seconds

    public function __construct(
        private GoPayClient $goPayClient,
        private ClockInterface $clock,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
        private int $pollIntervalMicroseconds = self::POLL_INTERVAL_MICROSECONDS,
    ) {
    }

    public function __invoke(ChargeRecurringPaymentCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        if (!$contract->hasActiveRecurringPayment()) {
            throw new \DomainException('Contract does not have active recurring payment.');
        }

        /** @var string $parentPaymentId */
        $parentPaymentId = $contract->goPayParentPaymentId;

        // Calculate amount (full month or prorated for last billing cycle)
        $amount = $this->calculateBillingAmount($contract, $now);

        if ($amount <= 0) {
            $this->logger->info('Skipping billing — zero or negative amount', [
                'contract_id' => $contract->id->toRfc4122(),
            ]);

            return;
        }

        try {
            // Charge via GoPay
            $payment = $this->goPayClient->createRecurrence(
                $parentPaymentId,
                $amount,
                $this->buildOrderNumber($contract->id, $now),
                $this->buildDescription($contract, $now),
            );

            // GoPay may return CREATED while the card charge is still processing.
            // Poll for confirmation before giving up.
            if ('PAID' !== $payment->state) {
                $payment = $this->pollForConfirmation($payment);
            }

            if ('PAID' !== $payment->state) {
                $this->logger->error('Recurring payment not confirmed by GoPay after polling', [
                    'gopay_payment_id' => $payment->id,
                    'state' => $payment->state,
                    'contract_id' => $contract->id->toRfc4122(),
                ]);

                throw PaymentNotConfirmedException::withState($payment->id, $payment->state);
            }

            // Use nextBillingDate as billing period start for deterministic proration
            /** @var \DateTimeImmutable $billingPeriodStart */
            $billingPeriodStart = $contract->nextBillingDate ?? $now;
            $effectiveEndDate = $contract->getEffectiveEndDate();
            $nextBillingDate = $billingPeriodStart->modify('+1 month');
            $paidThroughDate = $nextBillingDate;

            // If this was the last billing cycle, stop future charges
            if (null !== $effectiveEndDate && $nextBillingDate >= $effectiveEndDate) {
                $nextBillingDate = null; // No more charges
                $paidThroughDate = $effectiveEndDate;
            }

            // Update contract
            $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);

            $this->eventBus->dispatch(new RecurringPaymentCharged(
                contractId: $contract->id,
                paymentId: $payment->id,
                amount: $amount,
                occurredOn: $now,
            ));
        } catch (GoPayException|PaymentNotConfirmedException $e) {
            // Re-throw — failure recording is handled by the calling console command
            // outside the doctrine_transaction (which rolls back on exception).
            throw $e;
        }
    }

    /**
     * Poll GoPay for payment confirmation. Returns updated payment state.
     */
    private function pollForConfirmation(GoPayPayment $payment): GoPayPayment
    {
        for ($i = 0; $i < self::MAX_STATUS_POLLS; ++$i) {
            if ($this->pollIntervalMicroseconds > 0) {
                usleep($this->pollIntervalMicroseconds);
            }

            $status = $this->goPayClient->getStatus($payment->id);

            if (!$status->isPending()) {
                return new GoPayPayment($status->id, $payment->gwUrl, $status->state);
            }
        }

        return $payment;
    }

    private function calculateBillingAmount(Contract $contract, \DateTimeImmutable $now): int
    {
        $monthlyRate = $contract->storage->getEffectivePricePerMonth();
        $effectiveEndDate = $contract->getEffectiveEndDate();

        if (null === $effectiveEndDate) {
            return $monthlyRate;
        }

        // Use nextBillingDate as the canonical billing period start (deterministic)
        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $nextFullPeriodEnd = $billingPeriodStart->modify('+1 month');

        if ($nextFullPeriodEnd <= $effectiveEndDate) {
            return $monthlyRate;
        }

        // Prorate: only charge for remaining days until end
        $remainingDays = max(1, (int) $billingPeriodStart->diff($effectiveEndDate)->days);
        $dailyRate = $monthlyRate / self::DAYS_PER_MONTH;

        return max(1, (int) round($remainingDays * $dailyRate));
    }

    private function buildOrderNumber(Uuid $contractId, \DateTimeImmutable $now): string
    {
        return sprintf('REC-%s-%s', $contractId->toRfc4122(), $now->format('Ymd'));
    }

    private function buildDescription(Contract $contract, \DateTimeImmutable $now): string
    {
        return sprintf(
            'Pravidelná platba - %s (%s)',
            $contract->storage->storageType->name,
            $now->format('m/Y'),
        );
    }
}
