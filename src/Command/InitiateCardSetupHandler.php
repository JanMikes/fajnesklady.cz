<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogger;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\GoPay\GoPayClient;
use App\Value\GoPayPayment;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InitiateCardSetupHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private RecurringAmountCalculator $amountCalculator,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(InitiateCardSetupCommand $command): GoPayPayment
    {
        $contract = $command->contract;

        // Recurring track without a live token — covers the manual bank track
        // and card contracts whose recurring was cancelled. ONE_TIME never
        // reaches this handler (prolongation converts it to MANUAL first).
        if (!$contract->billingMode->isRecurring()
            || null !== $contract->goPayParentPaymentId
            || $contract->isFree()
            || $contract->isYearly()
            || $contract->isTerminated()) {
            throw new \DomainException('Přechod na platbu kartou není pro tuto smlouvu dostupný.');
        }

        $now = $this->clock->now();
        $amount = $this->amountCalculator->calculate($contract, $now);

        // The setup charge REPLACES the next manual cycle — the webhook
        // records it as that cycle's payment and stores the ON_DEMAND token.
        $payment = $this->goPayClient->createRecurringCharge(
            amount: $amount,
            orderNumber: sprintf('CSU-%s-%s', $contract->id->toRfc4122(), $now->format('Ymd')),
            orderDescription: sprintf(
                'Pronájem skladu %s - %s (nastavení platby kartou)',
                $contract->storage->number,
                $contract->storage->storageType->name,
            ),
            payerEmail: $contract->user->email,
            returnUrl: $command->returnUrl,
            notificationUrl: $command->notificationUrl,
        );

        $contract->startCardSetup($payment->id);

        // Consent record — GoPay requires it retained ≥ 12 months past the
        // recurring agreement's end; AuditLogger captures actor + IP + time.
        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'recurring_consent_given',
            payload: [
                'context' => 'prolongation_card_setup',
                'gopay_payment_id' => $payment->id,
                'amount' => $amount,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );

        return $payment;
    }
}
