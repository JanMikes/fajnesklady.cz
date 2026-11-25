<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Contract;
use App\Repository\PlatformSettingsRepository;
use App\Service\OrderStatusUrlGenerator;
use App\Value\CardPaymentStopSummary;

final readonly class CardPaymentStopSummaryFactory
{
    public function __construct(
        private RecurringAmountCalculator $amountCalculator,
        private PlatformSettingsRepository $settingsRepository,
        private OrderStatusUrlGenerator $statusUrlGenerator,
    ) {
    }

    public function create(Contract $contract, \DateTimeImmutable $now): CardPaymentStopSummary
    {
        $terminationDays = $this->settingsRepository->getSettings()->overdueTerminationDays;
        $today = $now->setTime(0, 0);

        // An admin extension (spec 086) moves the effective due date.
        $anchor = $contract->isTerminated() || $contract->isFree() ? null : $contract->effectiveDunningAnchor();
        $dueDay = $anchor?->setTime(0, 0);

        $daysOverdue = null !== $dueDay && $today > $dueDay ? (int) $dueDay->diff($today)->days : 0;
        // The termination countdown waits until an already-paid period ends
        // (mirrors TerminateOverdueContractsCommand).
        $countdownDay = null !== $anchor ? $contract->terminationCountdownAnchor()?->setTime(0, 0) : null;

        return new CardPaymentStopSummary(
            // Never the raw paidThroughDate — for card cycles that is the first
            // UNPAID day (the same date as "Další platba … splatná").
            paidThroughDate: $contract->displayPaidUntil(),
            endDate: $contract->endDate,
            nextDueDate: $dueDay,
            nextAmount: null !== $dueDay ? $this->amountCalculator->calculate($contract, $now) : null,
            daysOverdue: $daysOverdue,
            // VOP čl. XI.4.b: termination only after MORE than N days in arrears
            // (mirrors TerminateOverdueContractsCommand).
            lastDayToPay: $countdownDay?->modify(sprintf('+%d days', $terminationDays)),
            terminationFrom: $countdownDay?->modify(sprintf('+%d days', $terminationDays + 1)),
            overdueTerminationDays: $terminationDays,
            variableSymbol: $contract->order->variableSymbol,
            cardSetupUrl: null !== $dueDay && null === $contract->goPayParentPaymentId
                ? $this->statusUrlGenerator->generateCardSetup($contract)
                : null,
        );
    }
}
