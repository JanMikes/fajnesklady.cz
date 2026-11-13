<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Contract;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Service\GoPay\GoPayClient;
use App\Service\Payment\VariableSymbolGenerator;

/**
 * Moves a card-paid contract onto the bank-transfer track without touching its
 * billing schedule: the GoPay token is voided, but the billing anchor stays,
 * so the manual-billing cron requests the next cycle (QR + VS + GoPay link)
 * and the overdue sweep still applies if it goes unpaid.
 *
 * Used when the tenant prolongs and picks bank transfer, and when the tenant
 * stops card payments (Podmínky opakovaných plateb čl. VI: stopping card
 * payments does not end the lease nor the duty to pay for it).
 */
final readonly class CardToBankTransferSwitch
{
    public function __construct(
        private GoPayClient $goPayClient,
        private VariableSymbolGenerator $variableSymbolGenerator,
    ) {
    }

    public function switch(Contract $contract, \DateTimeImmutable $nextBillingDate): void
    {
        if (null !== $contract->goPayParentPaymentId) {
            $this->goPayClient->voidRecurrence($contract->goPayParentPaymentId);
        }

        // Clears the token AND the anchor — the anchor is restored right below.
        $contract->cancelRecurringPayment();
        $contract->clearCardRetryLadder();
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->scheduleNextBilling($nextBillingDate, null);
        $contract->order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);

        // Every manual-track contract needs a variable symbol for the payment
        // requests and FIO pairing.
        if (null === $contract->order->variableSymbol) {
            $contract->order->assignVariableSymbol($this->variableSymbolGenerator->generate($contract->order->id));
        }
    }
}
