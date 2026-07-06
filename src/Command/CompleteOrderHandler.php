<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Enum\BillingMode;
use App\Service\ContractService;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteOrderHandler
{
    public function __construct(
        private OrderService $orderService,
        private ContractService $contractService,
        private PriceCalculator $priceCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CompleteOrderCommand $command): Contract
    {
        $now = $this->clock->now();
        $order = $command->order;
        $contract = $this->orderService->completeOrder($order, $now);

        // Set up recurring billing dates. AUTO needs the GoPay parent token to be
        // stored on the contract; MANUAL has no token but still needs nextBillingDate
        // populated so SendManualBillingPaymentRequestsCommand picks it up. Cadence
        // is +1 month or +1 year depending on the contract's paymentFrequency
        // (spec 045 — yearly is always MANUAL_RECURRING).
        if (null !== $order->goPayParentPaymentId) {
            $cadenceStep = $contract->getBillingCadenceStep();
            $nextBillingDate = $now->modify($cadenceStep);
            $paidThroughDate = $nextBillingDate;

            // Cap paidThroughDate to the contract end
            if ($paidThroughDate > $contract->endDate) {
                $paidThroughDate = $contract->endDate;
            }

            // If contract ends before next billing, this is the only billing cycle
            if ($nextBillingDate >= $contract->endDate) {
                $nextBillingDate = null;
            }

            $contract->setRecurringPayment($order->goPayParentPaymentId, $nextBillingDate, $paidThroughDate);
        } elseif (BillingMode::MANUAL_RECURRING === $contract->billingMode && null === $contract->nextBillingDate && !$contract->isFree()) {
            // MANUAL_RECURRING + no external prepayment: seed the first nextBillingDate
            // so the per-cycle reminder cron has an anchor. The customer just paid
            // their first cycle externally / one-shot — the next one is due in one
            // cadence step. Skip when markExternallyPrepaid already set it (external
            // prepayment path in OrderService::completeOrder).
            $cadenceStep = $contract->getBillingCadenceStep();
            $nextBillingDate = $now->modify($cadenceStep);
            $paidThroughDate = $nextBillingDate;

            if ($paidThroughDate > $contract->endDate) {
                $paidThroughDate = $contract->endDate;
            }

            if ($nextBillingDate >= $contract->endDate) {
                $nextBillingDate = null;
            }

            $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);
        } elseif (BillingMode::ONE_TIME === $contract->billingMode && null === $contract->nextBillingDate) {
            // Spec 078 tranches: an upfront rental longer than 12 months is
            // paid in yearly tranches. The first tranche was just paid — anchor
            // the second tranche on the canonical partition date so the
            // manual-billing cron requests it (bank details + QR + VS) per the
            // e-mail rules. A ≤ 12-month upfront rental yields a single tranche
            // and keeps NO anchor: it stays outside every billing cron.
            $tranches = $this->priceCalculator->buildScheduleFromOrder($order)->entries;
            if (count($tranches) > 1) {
                $secondTrancheDate = $tranches[1]->chargeDate;
                $contract->recordBillingCharge($now, $secondTrancheDate, $secondTrancheDate);
            }
        }

        // Generate contract document and sign
        $this->contractService->generateDocument($contract, $order->signaturePath, $now, $order->signingPlace, $order->signedAt);
        $this->contractService->signContract($contract, $now);

        return $contract;
    }
}
