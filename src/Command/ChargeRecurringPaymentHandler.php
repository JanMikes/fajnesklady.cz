<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Event\RecurringPaymentCharged;
use App\Event\RecurringPaymentFailed;
use App\Service\GoPay\GoPayClient;
use App\Service\GoPay\GoPayException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ChargeRecurringPaymentHandler
{
    private const int DAYS_PER_MONTH = 30;

    public function __construct(
        private GoPayClient $goPayClient,
        private ClockInterface $clock,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
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
        $amount = $this->calculateBillingAmount($contract);

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

            // Only record success if GoPay confirms the payment
            if ('PAID' !== $payment->state) {
                $this->logger->error('Recurring payment not confirmed by GoPay', [
                    'gopay_payment_id' => $payment->id,
                    'state' => $payment->state,
                    'contract_id' => $contract->id->toRfc4122(),
                ]);

                throw new GoPayException(sprintf('GoPay returned non-PAID state: %s for payment %s', $payment->state, $payment->id));
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
        } catch (GoPayException $e) {
            // Record failed attempt
            $contract->recordFailedBillingAttempt($now);

            $this->eventBus->dispatch(new RecurringPaymentFailed(
                contractId: $contract->id,
                attempt: $contract->failedBillingAttempts,
                reason: $e->getMessage(),
                occurredOn: $now,
            ));

            throw $e;
        }
    }

    private function calculateBillingAmount(Contract $contract): int
    {
        $monthlyRate = $contract->storage->getEffectivePricePerMonth();
        $effectiveEndDate = $contract->getEffectiveEndDate();

        if (null === $effectiveEndDate) {
            return $monthlyRate;
        }

        // Use nextBillingDate as the canonical billing period start (deterministic)
        $billingPeriodStart = $contract->nextBillingDate ?? new \DateTimeImmutable();
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
