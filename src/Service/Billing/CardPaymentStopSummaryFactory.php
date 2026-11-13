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

        return new CardPaymentStopSummary(
            paidThroughDate: $contract->paidThroughDate,
            endDate: $contract->endDate,
            nextDueDate: $dueDay,
            nextAmount: null !== $dueDay ? $this->amountCalculator->calculate($contract, $now) : null,
            daysOverdue: $daysOverdue,
            // VOP čl. XI.4.b: termination only after MORE than N days in arrears
            // (mirrors TerminateOverdueContractsCommand).
            lastDayToPay: $dueDay?->modify(sprintf('+%d days', $terminationDays)),
            terminationFrom: $dueDay?->modify(sprintf('+%d days', $terminationDays + 1)),
            overdueTerminationDays: $terminationDays,
            variableSymbol: $contract->order->variableSymbol,
            cardSetupUrl: null !== $dueDay && null === $contract->goPayParentPaymentId
                ? $this->statusUrlGenerator->generateCardSetup($contract)
                : null,
        );
    }
}
