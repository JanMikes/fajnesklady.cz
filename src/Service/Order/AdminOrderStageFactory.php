<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\TerminationReason;
use App\Value\OverdueContractView;

/**
 * Computes the admin-header lifecycle stage of an order. Pure function of the
 * passed-in state (no repository access) so the decision tree is unit-testable.
 */
final readonly class AdminOrderStageFactory
{
    public function build(
        Order $order,
        ?Contract $contract,
        ?OverdueContractView $overdueView,
        ?ManualPaymentRequest $pendingManualPayment,
        int $amountMismatchCount,
        \DateTimeImmutable $now,
    ): AdminOrderStage {
        $problems = $this->collectProblems($order, $contract, $overdueView, $amountMismatchCount, $now);

        return match ($order->status) {
            OrderStatus::CANCELLED => new AdminOrderStage(
                label: 'Objednávka zrušena',
                sublabel: null !== $order->cancelledAt ? $order->cancelledAt->format('d.m.Y') : null,
                tone: AdminOrderStage::TONE_GRAY,
                problems: $problems,
                nextStep: null,
            ),
            OrderStatus::EXPIRED => new AdminOrderStage(
                label: 'Objednávka expirovala',
                sublabel: 'rezervace nebyla dokončena včas',
                tone: AdminOrderStage::TONE_GRAY,
                problems: $problems,
                nextStep: null,
            ),
            OrderStatus::PAID => new AdminOrderStage(
                label: 'Zaplaceno — dokončuje se smlouva',
                sublabel: null,
                tone: AdminOrderStage::TONE_BLUE,
                problems: $problems,
                nextStep: 'Systém vytváří smlouvu a aktivuje pronájem.',
            ),
            OrderStatus::CREATED,
            OrderStatus::RESERVED,
            OrderStatus::AWAITING_PAYMENT => $this->buildPreCompletionStage($order, $problems),
            OrderStatus::COMPLETED => $this->buildCompletedStage($order, $contract, $overdueView, $pendingManualPayment, $problems, $now),
        };
    }

    /**
     * @param list<string> $problems
     */
    private function buildPreCompletionStage(Order $order, array $problems): AdminOrderStage
    {
        if (null !== $order->signingToken && null === $order->signedAt) {
            return new AdminOrderStage(
                label: 'Čeká na podpis zákazníka',
                sublabel: sprintf('odkaz platí do %s', $order->expiresAt->format('d.m.Y')),
                tone: AdminOrderStage::TONE_AMBER,
                problems: $problems,
                nextStep: 'Zákazník má e-mailem odkaz k podpisu — po podpisu pokračuje platba / aktivace.',
            );
        }

        if (null !== $order->signedAt && null === $order->paidAt) {
            $nextStep = PaymentMethod::BANK_TRANSFER === $order->paymentMethod
                ? sprintf(
                    'Očekává se bankovní převod %s Kč%s.',
                    number_format($order->firstPaymentPrice / 100, 0, ',', ' '),
                    null !== $order->variableSymbol ? sprintf(' (VS %s)', $order->variableSymbol) : '',
                )
                : 'Čeká se na platbu zákazníka.';

            return new AdminOrderStage(
                label: 'Podepsáno — čeká na platbu',
                sublabel: null,
                tone: AdminOrderStage::TONE_AMBER,
                problems: $problems,
                nextStep: $nextStep,
            );
        }

        return new AdminOrderStage(
            label: OrderStatus::AWAITING_PAYMENT === $order->status ? 'Čeká na platbu' : 'Čeká na dokončení objednávky',
            sublabel: sprintf('expiruje %s', $order->expiresAt->format('d.m.Y')),
            tone: AdminOrderStage::TONE_AMBER,
            problems: $problems,
            nextStep: 'Zákazník zatím objednávku nedokončil (podpis / platba).',
        );
    }

    /**
     * @param list<string> $problems
     */
    private function buildCompletedStage(
        Order $order,
        ?Contract $contract,
        ?OverdueContractView $overdueView,
        ?ManualPaymentRequest $pendingManualPayment,
        array $problems,
        \DateTimeImmutable $now,
    ): AdminOrderStage {
        if (null === $contract) {
            return new AdminOrderStage(
                label: 'Objednávka dokončena',
                sublabel: null,
                tone: AdminOrderStage::TONE_GREEN,
                problems: $problems,
                nextStep: null,
            );
        }

        if ($contract->isTerminated()) {
            $isPaymentFailure = TerminationReason::PAYMENT_FAILURE === $contract->terminationReason;

            return new AdminOrderStage(
                label: $isPaymentFailure ? 'Smlouva ukončena pro neplacení' : 'Smlouva ukončena',
                sublabel: null !== $contract->terminatedAt ? $contract->terminatedAt->format('d.m.Y') : null,
                tone: $isPaymentFailure || $contract->hasOutstandingDebt() ? AdminOrderStage::TONE_RED : AdminOrderStage::TONE_GRAY,
                problems: $problems,
                nextStep: null,
            );
        }

        $today = $now->setTime(0, 0, 0);
        $nextStep = $this->describeNextBillingStep($order, $contract, $pendingManualPayment);

        if ($contract->endDate < $today) {
            return new AdminOrderStage(
                label: 'Smlouva doběhla',
                sublabel: sprintf('skončila %s, čeká na ukončení', $contract->endDate->format('d.m.Y')),
                tone: AdminOrderStage::TONE_GRAY,
                problems: $problems,
                nextStep: $nextStep,
            );
        }

        if ($contract->hasPendingTermination()) {
            return new AdminOrderStage(
                label: 'Aktivní — výpověď podána',
                sublabel: null !== $contract->terminatesAt ? sprintf('končí %s', $contract->terminatesAt->format('d.m.Y')) : null,
                tone: AdminOrderStage::TONE_AMBER,
                problems: $problems,
                nextStep: $nextStep,
            );
        }

        if (null !== $overdueView) {
            return new AdminOrderStage(
                label: 'Aktivní — platba po splatnosti',
                sublabel: sprintf('%d %s po splatnosti', $overdueView->daysOverdue, $this->pluralDays($overdueView->daysOverdue)),
                tone: AdminOrderStage::TONE_RED,
                problems: $problems,
                nextStep: $nextStep,
            );
        }

        if ($contract->isInPaymentGrace($now)) {
            return new AdminOrderStage(
                label: 'Aktivní — splatnost prodloužena',
                sublabel: null !== $contract->paymentGraceUntil ? sprintf('do %s', $contract->paymentGraceUntil->format('d.m.Y')) : null,
                tone: AdminOrderStage::TONE_AMBER,
                problems: $problems,
                nextStep: $nextStep,
            );
        }

        return new AdminOrderStage(
            label: 'Aktivní pronájem',
            sublabel: sprintf('do %s', $contract->endDate->format('d.m.Y')),
            tone: AdminOrderStage::TONE_GREEN,
            problems: $problems,
            nextStep: $nextStep,
        );
    }

    private function describeNextBillingStep(Order $order, Contract $contract, ?ManualPaymentRequest $pendingManualPayment): ?string
    {
        if ($contract->isFree()) {
            return 'Smlouva zdarma — nic se neúčtuje.';
        }

        if (null !== $pendingManualPayment) {
            return sprintf(
                'Očekává se bankovní převod %s Kč za období od %s%s.',
                number_format($pendingManualPayment->amount / 100, 0, ',', ' '),
                $pendingManualPayment->periodStart->format('d.m.Y'),
                null !== $order->variableSymbol ? sprintf(' (VS %s)', $order->variableSymbol) : '',
            );
        }

        if (null === $contract->nextBillingDate) {
            return null !== $contract->paidThroughDate
                ? sprintf('Zaplaceno do konce smlouvy (%s) — nic dalšího se neúčtuje.', $contract->paidThroughDate->format('d.m.Y'))
                : null;
        }

        if (BillingMode::AUTO_RECURRING === $contract->billingMode) {
            return sprintf('Další automatická platba kartou %s.', $contract->nextBillingDate->format('d.m.Y'));
        }

        return sprintf('Další období začíná %s — výzva k platbě se odešle e-mailem.', $contract->nextBillingDate->format('d.m.Y'));
    }

    /**
     * @return list<string>
     */
    private function collectProblems(
        Order $order,
        ?Contract $contract,
        ?OverdueContractView $overdueView,
        int $amountMismatchCount,
        \DateTimeImmutable $now,
    ): array {
        $problems = [];

        if (null !== $overdueView) {
            $problems[] = sprintf(
                '%s Kč po splatnosti od %s (%d %s) — %s%s',
                number_format($overdueView->overdueAmount / 100, 0, ',', ' '),
                $overdueView->anchorDate->format('d.m.Y'),
                $overdueView->daysOverdue,
                $this->pluralDays($overdueView->daysOverdue),
                $overdueView->reasonLabel,
                null !== $order->variableSymbol ? sprintf(', VS %s', $order->variableSymbol) : '',
            );
        }

        if ($order->hasUnpaidDebt() && null !== $order->onboardingDebtInHaler) {
            $problems[] = sprintf(
                'Neuhrazený dluh z předchozí smlouvy: %s Kč',
                number_format($order->onboardingDebtInHaler / 100, 0, ',', ' '),
            );
        }

        if (null !== $contract && $contract->isTerminated() && $contract->hasOutstandingDebt() && null !== $contract->outstandingDebtAmount) {
            $problems[] = sprintf(
                'Evidovaný dluh po ukončení: %s Kč',
                number_format($contract->outstandingDebtAmount / 100, 0, ',', ' '),
            );
        }

        if ($amountMismatchCount > 0) {
            $problems[] = sprintf(
                '%d× neshoda částky bankovního převodu — viz upozornění níže',
                $amountMismatchCount,
            );
        }

        if (null !== $contract && $contract->failedBillingAttempts > 0 && null === $overdueView) {
            $problems[] = sprintf('Poslední automatická platba selhala (%d×)', $contract->failedBillingAttempts);
        }

        return $problems;
    }

    private function pluralDays(int $days): string
    {
        return 1 === $days ? 'den' : ($days < 5 ? 'dny' : 'dní');
    }
}
