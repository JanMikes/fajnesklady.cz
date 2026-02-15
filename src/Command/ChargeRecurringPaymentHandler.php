<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PaymentFrequency;
use App\Event\RecurringPaymentCharged;
use App\Event\RecurringPaymentFailed;
use App\Service\GoPay\GoPayClient;
use App\Service\GoPay\GoPayException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ChargeRecurringPaymentHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private ClockInterface $clock,
        private MessageBusInterface $eventBus,
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
        $storageType = $contract->storage->storageType;
        $paymentFrequency = $contract->order->paymentFrequency;

        // Calculate amount based on payment frequency
        $amount = $this->calculateBillingAmount($paymentFrequency, $storageType);

        try {
            // Charge via GoPay
            $payment = $this->goPayClient->createRecurrence(
                $parentPaymentId,
                $amount,
                $this->buildOrderNumber($contract->id, $now),
                $this->buildDescription($contract, $now),
            );

            // Calculate next billing date
            $nextBillingDate = $this->calculateNextBillingDate($now, $paymentFrequency);

            // Update contract
            $contract->recordBillingCharge($now, $nextBillingDate);

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

    private function calculateBillingAmount(?PaymentFrequency $frequency, \App\Entity\StorageType $storageType): int
    {
        return match ($frequency) {
            PaymentFrequency::YEARLY => $storageType->defaultPricePerMonth * 12,
            default => $storageType->defaultPricePerMonth,
        };
    }

    private function calculateNextBillingDate(\DateTimeImmutable $now, ?PaymentFrequency $frequency): \DateTimeImmutable
    {
        return match ($frequency) {
            PaymentFrequency::YEARLY => $now->modify('+1 year'),
            default => $now->modify('+1 month'),
        };
    }

    private function buildOrderNumber(Uuid $contractId, \DateTimeImmutable $now): string
    {
        return sprintf('REC-%s-%s', $contractId->toRfc4122(), $now->format('Ymd'));
    }

    private function buildDescription(\App\Entity\Contract $contract, \DateTimeImmutable $now): string
    {
        return sprintf(
            'Pravidelná platba - %s (%s)',
            $contract->storage->storageType->name,
            $now->format('m/Y'),
        );
    }
}
