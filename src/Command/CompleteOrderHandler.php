<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Enum\BillingMode;
use App\Service\ContractService;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteOrderHandler
{
    public function __construct(
        private OrderService $orderService,
        private ContractService $contractService,
        private PriceCalculator $priceCalculator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteOrderCommand $command): Contract
    {
        $now = $this->clock->now();
        $order = $command->order;
        $contract = $this->orderService->completeOrder($order, $now);

        // An admin-recorded external prepayment (OrderService::completeOrder →
        // markExternallyPrepaid) already decided the billing state — including
        // "paid to the end of the term", which deliberately leaves NO anchor.
        // Never re-seed over it, or a fully prepaid customer gets billed.
        $externallyPrepaid = null !== $order->paidThroughDate;

        // Set up recurring billing. AUTO needs the GoPay parent token stored on
        // the contract; MANUAL has no token but still needs nextBillingDate so
        // SendManualBillingPaymentRequestsCommand picks it up. Every payment
        // type follows the same rule (2026-11-25): the first payment pays the
        // first quoted period [start, start + cadence); later periods are due on
        // the first payment's day of month (the recurring consent's "vždy ke
        // stejnému dni v měsíci jako první platba"), the last one prorated to
        // the real end — see Contract::startBilling().
        if (null !== $order->goPayParentPaymentId) {
            $contract->attachCardToken($order->goPayParentPaymentId);
            $this->startBilling($contract, $now, recordAsBilled: false);
        } elseif (BillingMode::MANUAL_RECURRING === $contract->billingMode && !$externallyPrepaid && !$contract->isFree()) {
            $this->startBilling($contract, $now);
        } elseif (BillingMode::ONE_TIME === $contract->billingMode && !$externallyPrepaid) {
            // Spec 078 tranches: an upfront rental longer than 12 months is
            // paid in yearly tranches. The first tranche was just paid — the
            // second one (the period from start + 12 months) is due on the
            // first payment's day, like every other cycle, so the manual-billing
            // cron requests it (bank details + QR + VS). A ≤ 12-month upfront
            // rental yields a single tranche and keeps NO anchor: it stays
            // outside every billing cron.
            $tranches = $this->priceCalculator->buildScheduleFromOrder($order)->entries;
            if (count($tranches) > 1) {
                $this->startBilling($contract, $now);
            }
        }

        // Generate contract document and sign
        $this->contractService->generateDocument($contract, $order->signaturePath, $now, $order->signingPlace, $order->signedAt);
        $this->contractService->signContract($contract, $now);

        return $contract;
    }

    /**
     * Fallback: if the first period has already run out (payment a whole
     * cadence late), there is no future period to anchor on — keep the
     * payment-time single walk, never a due date in the past (a second card
     * charge the same day, or a brand-new manual contract landing straight in
     * the overdue stages).
     */
    private function startBilling(Contract $contract, \DateTimeImmutable $now, bool $recordAsBilled = true): void
    {
        if ($contract->firstCycleEnd() > $now->setTime(0, 0)) {
            $contract->startBilling($now, $recordAsBilled);

            return;
        }

        $this->logger->warning('First payment arrived after the whole first billing period; anchoring billing on the payment date.', [
            'contract_id' => $contract->id->toRfc4122(),
            'start_date' => $contract->startDate->format('Y-m-d'),
            'paid_at' => $now->format(\DATE_ATOM),
        ]);

        $nextBillingDate = $contract->advanceByCadence($now);
        $endDate = $contract->endDate;
        $paidThroughDate = $nextBillingDate >= $endDate ? $endDate : $nextBillingDate;
        $nextBillingDate = $nextBillingDate >= $endDate ? null : $nextBillingDate;
        if (!$recordAsBilled && null !== $contract->goPayParentPaymentId) {
            // Card: same as before two-walk billing — not a "billed" cycle.
            $contract->setRecurringPayment($contract->goPayParentPaymentId, $nextBillingDate, $paidThroughDate);
        } else {
            $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);
        }
    }
}
