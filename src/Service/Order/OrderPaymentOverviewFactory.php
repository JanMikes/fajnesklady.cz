<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Payment;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\Billing\RecurringAmountCalculator;

/**
 * Builds the admin "Přehled plateb" — one unified expected-vs-paid table for
 * an order's whole money lifecycle: externally-prepaid onboarding period,
 * migrated-in debt, first payment, every billing cycle that already has a
 * ManualPaymentRequest or Payment row, and the projected future cycles that
 * don't exist in the database yet.
 */
final readonly class OrderPaymentOverviewFactory
{
    private const int MAX_PROJECTED_CYCLES = 24;

    // Only for paid rows whose source was never recorded (pre-backfill data).
    private const string UNKNOWN_SOURCE = 'Neuvedeno';

    public function __construct(
        private RecurringAmountCalculator $amountCalculator,
    ) {
    }

    /**
     * Pure function of the passed-in state (no repository access) so the row
     * derivation is unit-testable. The caller fetches:
     *
     * @param list<Payment>              $payments PaymentRepository::findAllForOrder()
     * @param list<ManualPaymentRequest> $requests ManualPaymentRequestRepository::findAllByContract()
     */
    public function build(Order $order, ?Contract $contract, array $payments, array $requests, \DateTimeImmutable $now): OrderPaymentOverview
    {
        $today = $now->setTime(0, 0, 0);
        $rows = [];

        $prepaidRow = $this->buildExternallyPrepaidRow($order);
        if (null !== $prepaidRow) {
            $rows[] = $prepaidRow;
        }

        $debtRow = $this->buildDebtRow($order, $today);
        if (null !== $debtRow) {
            $rows[] = $debtRow;
        }

        $firstPaymentRow = $this->buildFirstPaymentRow($order, $payments, null !== $prepaidRow, $today);
        if (null !== $firstPaymentRow) {
            $rows[] = $firstPaymentRow;
        }

        foreach ($requests as $request) {
            $rows[] = $this->buildRequestRow($order, $contract, $request, $payments, $today);
        }

        foreach ($this->buildUnmatchedContractPaymentRows($payments, $requests) as $row) {
            $rows[] = $row;
        }

        foreach ($this->buildProjectedCycleRows($order, $contract, $requests, $today) as $row) {
            $rows[] = $row;
        }

        // A terminated contract's residual debt is a receivable like any other.
        if (null !== $contract && $contract->isTerminated() && $contract->hasOutstandingDebt() && null !== $contract->outstandingDebtAmount) {
            $rows[] = new PaymentOverviewRow(
                label: 'Dluh po ukončení smlouvy',
                status: PaymentOverviewRow::STATUS_OVERDUE,
                dueDate: $contract->terminatedAt,
                amountInHaler: $contract->outstandingDebtAmount,
                note: null !== $order->variableSymbol ? sprintf('VS %s', $order->variableSymbol) : null,
                daysOverdue: null !== $contract->terminatedAt
                    ? max(1, (int) $contract->terminatedAt->setTime(0, 0, 0)->diff($today)->days)
                    : null,
            );
        }

        usort($rows, static fn (PaymentOverviewRow $a, PaymentOverviewRow $b): int => ($a->sortDate() ?? new \DateTimeImmutable('@0')) <=> ($b->sortDate() ?? new \DateTimeImmutable('@0')));

        $totalPaid = 0;
        foreach ($payments as $payment) {
            $totalPaid += $payment->amount;
        }

        $overdueTotal = 0;
        $outstandingTotal = 0;
        foreach ($rows as $row) {
            if (PaymentOverviewRow::STATUS_OVERDUE === $row->status) {
                $overdueTotal += $row->amountInHaler ?? 0;
            }
            if (in_array($row->status, [PaymentOverviewRow::STATUS_OVERDUE, PaymentOverviewRow::STATUS_PENDING, PaymentOverviewRow::STATUS_SCHEDULED], true)) {
                $outstandingTotal += $row->amountInHaler ?? 0;
            }
        }

        return new OrderPaymentOverview(
            rows: $rows,
            totalPaidInHaler: $totalPaid,
            overdueTotalInHaler: $overdueTotal,
            outstandingTotalInHaler: $outstandingTotal,
        );
    }

    private function buildExternallyPrepaidRow(Order $order): ?PaymentOverviewRow
    {
        // A contract's paidThroughDate also advances with regular in-system
        // charges — only the onboarding marker on the ORDER proves the period
        // was settled outside the platform.
        if (null === $order->paidThroughDate) {
            return null;
        }

        return new PaymentOverviewRow(
            label: 'Předplaceno mimo systém (onboarding)',
            status: PaymentOverviewRow::STATUS_COVERED_EXTERNAL,
            periodStart: $order->startDate,
            periodEnd: $order->paidThroughDate,
            source: PaymentMethod::EXTERNAL->label(),
            note: sprintf('Uhrazeno mimo platformu do %s — v systému neprošla žádná platba.', $order->paidThroughDate->format('d.m.Y')),
        );
    }

    private function buildDebtRow(Order $order, \DateTimeImmutable $today): ?PaymentOverviewRow
    {
        if (!$order->hasDebt()) {
            return null;
        }

        if (null !== $order->debtPaidAt) {
            return new PaymentOverviewRow(
                label: 'Dluh z předchozí smlouvy',
                status: PaymentOverviewRow::STATUS_PAID,
                dueDate: $order->createdAt,
                amountInHaler: $order->onboardingDebtInHaler,
                paidAt: $order->debtPaidAt,
                // debtGoPayPaymentId is NOT proof of a card payment — it is set
                // as soon as the customer opens the gateway.
                source: $order->debtPaidVia?->label() ?? self::UNKNOWN_SOURCE,
            );
        }

        return new PaymentOverviewRow(
            label: 'Dluh z předchozí smlouvy',
            status: PaymentOverviewRow::STATUS_OVERDUE,
            dueDate: $order->createdAt,
            amountInHaler: $order->onboardingDebtInHaler,
            daysOverdue: max(0, (int) $order->createdAt->setTime(0, 0, 0)->diff($today)->days),
        );
    }

    /**
     * @param list<Payment> $payments
     */
    private function buildFirstPaymentRow(Order $order, array $payments, bool $hasPrepaidRow, \DateTimeImmutable $today): ?PaymentOverviewRow
    {
        $initialPayment = null;
        foreach ($payments as $payment) {
            if (null !== $payment->order) {
                $initialPayment = $payment;

                break;
            }
        }

        if (null !== $initialPayment) {
            // The zero-amount formality row (external settle / free contract)
            // carries no information beyond the prepaid row above it.
            if (0 === $initialPayment->amount && $hasPrepaidRow) {
                return null;
            }

            if (0 === $initialPayment->amount) {
                return new PaymentOverviewRow(
                    label: 'První platba',
                    status: PaymentOverviewRow::STATUS_COVERED_EXTERNAL,
                    paidAt: $initialPayment->paidAt,
                    source: PaymentMethod::EXTERNAL->label(),
                    note: 'Vyřízeno bez platby v systému (předplaceno mimo systém nebo zdarma).',
                );
            }

            // How the money ACTUALLY arrived — never the order's chosen method:
            // a card order can be settled by a transfer to its VS. A GoPay /
            // bank link on the Payment is hard evidence; Order::$paidVia comes
            // next; an unlinked legacy row is "unknown" rather than a guess.
            $paidVia = match (true) {
                PaymentMethod::EXTERNAL !== $initialPayment->paidVia => $initialPayment->paidVia,
                null !== $order->paidVia => $order->paidVia,
                PaymentMethod::EXTERNAL === $order->paymentMethod => PaymentMethod::EXTERNAL,
                default => null,
            };

            return new PaymentOverviewRow(
                label: 'První platba',
                status: PaymentOverviewRow::STATUS_PAID,
                amountInHaler: $initialPayment->amount,
                paidAt: $initialPayment->paidAt,
                source: $paidVia?->label() ?? self::UNKNOWN_SOURCE,
                note: PaymentMethod::EXTERNAL === $paidVia ? 'Částku zaznamenal administrátor — peníze neprošly platební bránou ani párováním z banky.' : null,
            );
        }

        // No money yet — show what the system is waiting for. EXTERNAL orders
        // never collect a first payment (they auto-complete as a 0 Kč
        // formality at signature), so projecting an amount would be a lie.
        if (!$order->canBePaid() || PaymentMethod::EXTERNAL === $order->paymentMethod) {
            return null;
        }

        if (null !== $order->signingToken && null === $order->signedAt) {
            return new PaymentOverviewRow(
                label: 'První platba',
                status: PaymentOverviewRow::STATUS_SCHEDULED,
                dueDate: $order->expiresAt,
                amountInHaler: $order->firstPaymentPrice,
                note: 'Čeká na podpis zákazníka — platba následuje po podpisu.',
            );
        }

        $isOverdue = $order->expiresAt->setTime(0, 0, 0) < $today;

        return new PaymentOverviewRow(
            label: 'První platba',
            status: $isOverdue ? PaymentOverviewRow::STATUS_OVERDUE : PaymentOverviewRow::STATUS_PENDING,
            dueDate: $order->expiresAt,
            amountInHaler: $order->firstPaymentPrice,
            note: 'Čeká na zaplacení zákazníkem.',
            daysOverdue: $isOverdue
                ? max(1, (int) $order->expiresAt->setTime(0, 0, 0)->diff($today)->days)
                : null,
        );
    }

    /**
     * @param list<Payment> $payments
     */
    private function buildRequestRow(Order $order, ?Contract $contract, ManualPaymentRequest $request, array $payments, \DateTimeImmutable $today): PaymentOverviewRow
    {
        $label = sprintf(
            'Období %s – %s',
            $request->periodStart->format('d.m.Y'),
            $request->periodEnd->format('d.m.Y'),
        );

        if ($request->isPaid()) {
            return new PaymentOverviewRow(
                label: $label,
                status: PaymentOverviewRow::STATUS_PAID,
                dueDate: $request->periodStart,
                periodStart: $request->periodStart,
                periodEnd: $request->periodEnd,
                amountInHaler: $request->amount,
                paidAt: $request->paidAt,
                source: $this->findSettlingPayment($request, $payments)?->paidVia->label() ?? self::UNKNOWN_SOURCE,
            );
        }

        if (in_array($request->status, [ManualPaymentRequest::STATUS_CANCELLED, ManualPaymentRequest::STATUS_EXPIRED], true)) {
            return new PaymentOverviewRow(
                label: $label,
                status: PaymentOverviewRow::STATUS_CANCELLED,
                dueDate: $request->periodStart,
                periodStart: $request->periodStart,
                periodEnd: $request->periodEnd,
                amountInHaler: $request->amount,
            );
        }

        $lastStageNote = $this->describeLastSentStage($request);
        $vsNote = null !== $order->variableSymbol ? sprintf('VS %s', $order->variableSymbol) : null;

        // An admin-granted deadline extension (spec 086) suspends dunning for
        // the CURRENT cycle — while it lasts, the row is "waiting", not "overdue".
        $graceUntil = $contract?->paymentGraceUntil;
        if (null !== $graceUntil && $graceUntil->setTime(0, 0, 0) < $today) {
            $graceUntil = null; // extension already elapsed
        }
        $dueDate = $graceUntil ?? $request->periodStart;

        if ($request->periodStart->setTime(0, 0, 0) <= $today && null === $graceUntil) {
            return new PaymentOverviewRow(
                label: $label,
                status: PaymentOverviewRow::STATUS_OVERDUE,
                dueDate: $dueDate,
                periodStart: $request->periodStart,
                periodEnd: $request->periodEnd,
                amountInHaler: $request->amount,
                note: implode(' · ', array_filter([$vsNote, $lastStageNote])),
                daysOverdue: max(1, (int) $request->periodStart->setTime(0, 0, 0)->diff($today)->days),
            );
        }

        return new PaymentOverviewRow(
            label: $label,
            status: PaymentOverviewRow::STATUS_PENDING,
            dueDate: $dueDate,
            periodStart: $request->periodStart,
            periodEnd: $request->periodEnd,
            amountInHaler: $request->amount,
            note: implode(' · ', array_filter([
                null !== $graceUntil ? sprintf('Splatnost prodloužena do %s', $graceUntil->format('d.m.Y')) : null,
                $vsNote,
                $lastStageNote,
            ])),
        );
    }

    /**
     * Contract-linked payments that don't belong to a paid request row —
     * recurring card charges and stand-alone external/bank records. Payments
     * confirmed together with a request share its exact paidAt timestamp
     * (same $now inside one handler), which is the merge key.
     *
     * @param list<Payment>              $payments
     * @param list<ManualPaymentRequest> $requests
     *
     * @return list<PaymentOverviewRow>
     */
    private function buildUnmatchedContractPaymentRows(array $payments, array $requests): array
    {
        $requestPaidAts = [];
        foreach ($requests as $request) {
            if (null !== $request->paidAt) {
                $requestPaidAts[$request->paidAt->format(\DateTimeInterface::ATOM)] = true;
            }
        }

        $rows = [];
        foreach ($payments as $payment) {
            if (null !== $payment->order) {
                continue; // first payment, rendered separately
            }
            if (isset($requestPaidAts[$payment->paidAt->format(\DateTimeInterface::ATOM)])) {
                continue; // already visible as a paid request row
            }

            // With a known cycle the row reads like a request row and sorts into
            // its slot in the schedule; without one (historic rows) it stays a
            // bare "Opakovaná platba" floating at the top.
            $hasPeriod = null !== $payment->periodStart && null !== $payment->periodEnd;

            $rows[] = new PaymentOverviewRow(
                label: $hasPeriod
                    ? sprintf('Období %s – %s', $payment->periodStart->format('d.m.Y'), $payment->periodEnd->format('d.m.Y'))
                    : 'Opakovaná platba',
                status: PaymentOverviewRow::STATUS_PAID,
                dueDate: $payment->periodStart,
                periodStart: $payment->periodStart,
                periodEnd: $payment->periodEnd,
                amountInHaler: $payment->amount,
                paidAt: $payment->paidAt,
                source: $payment->paidVia->label(),
            );
        }

        return $rows;
    }

    /**
     * The Payment row that settled a paid request. Both are written by the same
     * handler with the same $now, so paidAt is the join key (the same one
     * {@see buildUnmatchedContractPaymentRows()} uses to avoid double rows).
     *
     * @param list<Payment> $payments
     */
    private function findSettlingPayment(ManualPaymentRequest $request, array $payments): ?Payment
    {
        if (null === $request->paidAt) {
            return null;
        }

        foreach ($payments as $payment) {
            if (null === $payment->order && $payment->paidAt->format(\DateTimeInterface::ATOM) === $request->paidAt->format(\DateTimeInterface::ATOM)) {
                return $payment;
            }
        }

        // Not inferred from $request->goPayPaymentId: that is set when the
        // customer opens the card gateway, not when the money arrives.
        return null;
    }

    /**
     * Future billing cycles that exist only as a projection — nothing in the
     * database yet. Walks the contract's cadence from nextBillingDate to the
     * effective end date.
     *
     * @param list<ManualPaymentRequest> $requests
     *
     * @return list<PaymentOverviewRow>
     */
    private function buildProjectedCycleRows(Order $order, ?Contract $contract, array $requests, \DateTimeImmutable $today): array
    {
        if (null === $contract || $contract->isTerminated() || $contract->isFree() || null === $contract->nextBillingDate) {
            return [];
        }

        $existingPeriods = [];
        foreach ($requests as $request) {
            $existingPeriods[$request->periodStart->format('Y-m-d')] = true;
        }

        $isAutoRecurring = BillingMode::AUTO_RECURRING === $contract->billingMode;
        $endDate = $contract->getEffectiveEndDate();
        $schedule = ManualBillingReminderSchedule::fromOrder($order);

        $rows = [];
        $periodStart = $contract->nextBillingDate->setTime(0, 0, 0);
        $isFirstProjected = true;

        while ($periodStart <= $endDate && count($rows) < self::MAX_PROJECTED_CYCLES) {
            $nextPeriodStart = $contract->advanceByCadence($periodStart);
            $periodEnd = $nextPeriodStart->modify('-1 day');
            if ($periodEnd > $endDate) {
                $periodEnd = $endDate;
            }

            if (!isset($existingPeriods[$periodStart->format('Y-m-d')])) {
                // A cycle whose start already passed without any request or
                // payment row is due NOW (auto-recurring charge overdue, or a
                // manual request the cron hasn't issued yet) — an admin-granted
                // grace keeps it "waiting" instead of "overdue".
                $isDue = $periodStart <= $today;
                $inGrace = $isDue && null !== $contract->paymentGraceUntil
                    && $contract->paymentGraceUntil->setTime(0, 0, 0) >= $today;

                $status = PaymentOverviewRow::STATUS_SCHEDULED;
                $daysOverdue = null;
                if ($isDue) {
                    $status = $inGrace ? PaymentOverviewRow::STATUS_PENDING : PaymentOverviewRow::STATUS_OVERDUE;
                    $daysOverdue = $inGrace ? null : max(1, (int) $periodStart->diff($today)->days);
                }

                $note = null;
                $graceUntil = $contract->paymentGraceUntil;
                if ($inGrace && null !== $graceUntil) {
                    $note = sprintf('Splatnost prodloužena do %s', $graceUntil->format('d.m.Y'));
                } elseif ($isDue) {
                    $note = $isAutoRecurring ? 'Strhnutí z karty splatné' : 'Výzva k platbě zatím neodeslána';
                } elseif ($isFirstProjected) {
                    $note = $isAutoRecurring
                        ? 'Strhne se automaticky z karty.'
                        : sprintf('Výzva k platbě se odešle %s.', $periodStart->modify(sprintf('%d days', $schedule->offsetInitial))->format('d.m.Y'));
                }

                $rows[] = new PaymentOverviewRow(
                    label: sprintf('Období %s – %s', $periodStart->format('d.m.Y'), $periodEnd->format('d.m.Y')),
                    status: $status,
                    dueDate: $periodStart,
                    periodStart: $periodStart,
                    periodEnd: $periodEnd,
                    amountInHaler: $this->amountCalculator->calculateForPeriodStart($contract, $periodStart),
                    source: $isAutoRecurring ? 'Platební karta (automaticky)' : 'Bankovní převod (na výzvu)',
                    note: $note,
                    daysOverdue: $daysOverdue,
                );
                $isFirstProjected = false;
            }

            $periodStart = $nextPeriodStart;
        }

        return $rows;
    }

    private function describeLastSentStage(ManualPaymentRequest $request): string
    {
        $stageLabels = [
            ManualBillingReminderSchedule::STAGE_INITIAL => 'úvodní výzva',
            ManualBillingReminderSchedule::STAGE_REMINDER => 'připomínka',
            ManualBillingReminderSchedule::STAGE_FINAL_DUE => 'splatné dnes',
            ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST => 'upomínka',
            ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL => 'poslední upomínka',
        ];

        $lastStage = null;
        $lastSentAt = null;
        foreach ($request->sentStages as $stage => $sentAt) {
            $sentAtDate = new \DateTimeImmutable($sentAt);
            if (null === $lastSentAt || $sentAtDate > $lastSentAt) {
                $lastSentAt = $sentAtDate;
                $lastStage = $stage;
            }
        }

        if (null === $lastSentAt) {
            return 'Zatím neodeslána žádná výzva';
        }

        return sprintf('Poslední e-mail: %s (%s)', $stageLabels[$lastStage] ?? $lastStage, $lastSentAt->format('d.m.Y'));
    }
}
