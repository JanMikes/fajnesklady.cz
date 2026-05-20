<?php

declare(strict_types=1);

namespace App\Service\Operations;

use App\Enum\HandoverStatus;
use App\Repository\ContractRepository;
use App\Repository\HandoverProtocolRepository;
use App\Repository\OrderRepository;
use App\Service\Overdue\OverdueChecker;
use App\Value\OperationsAlertSummary;

/**
 * Builds the admin Operations hub snapshot. Aggregates 5 signals:
 *   - handover protocols waiting on the tenant / landlord (or both),
 *   - contracts ending in ≤7 d that don't yet have a protocol row,
 *   - admin-created onboarding orders that are signed but unpaid,
 *   - externally-prepaid contracts within ≤7 d of paid-through end,
 *   - overdue (link-out only — full list stays on /portal/admin/po-splatnosti).
 *
 * The "handoverOverdue" bucket flags protocols sitting > 14 d unpicked-up,
 * so admins can spot stuck protocols before the +14-day force-release fires.
 */
final readonly class OperationsAlertsBuilder
{
    private const int CONTRACTS_ENDING_WINDOW_DAYS = 7;
    private const int EXTERNAL_PREPAYMENT_WINDOW_DAYS = 7;
    private const int HANDOVER_OVERDUE_DAYS = 14;

    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private OverdueChecker $overdueChecker,
    ) {
    }

    public function build(\DateTimeImmutable $now): OperationsAlertSummary
    {
        $handoverViews = $this->handoverProtocolRepository->findPending();
        $waitingTenant = 0;
        $waitingLandlord = 0;
        $handoverOverdue = 0;
        $overdueThreshold = $now->modify('-' . self::HANDOVER_OVERDUE_DAYS . ' days');

        foreach ($handoverViews as $protocol) {
            if ($protocol->status->isWaitingOn('tenant')) {
                ++$waitingTenant;
            }
            if ($protocol->status->isWaitingOn('landlord')) {
                ++$waitingLandlord;
            }
            if ($protocol->createdAt < $overdueThreshold && HandoverStatus::COMPLETED !== $protocol->status) {
                ++$handoverOverdue;
            }
        }

        $contractsEnding = $this->contractRepository->findExpiringWithoutProtocol(
            self::CONTRACTS_ENDING_WINDOW_DAYS,
            $now,
        );
        $onboardingSignedUnpaid = $this->orderRepository->findUnpaidSignedOnboarding($now);
        $externalPrepaymentEnding = $this->contractRepository->findExternalPrepaymentEndingWithinDays(
            self::EXTERNAL_PREPAYMENT_WINDOW_DAYS,
            $now,
        );

        $overdueSummary = $this->overdueChecker->summarise($now);

        $totalPending = count($handoverViews)
            + count($contractsEnding)
            + count($onboardingSignedUnpaid)
            + count($externalPrepaymentEnding);

        return new OperationsAlertSummary(
            handoverWaitingTenantCount: $waitingTenant,
            handoverWaitingLandlordCount: $waitingLandlord,
            handoverOverdueCount: $handoverOverdue,
            handoverViews: $handoverViews,
            contractsEndingWithoutProtocolCount: count($contractsEnding),
            contractsEndingWithoutProtocol: $contractsEnding,
            onboardingSignedUnpaidCount: count($onboardingSignedUnpaid),
            onboardingSignedUnpaid: $onboardingSignedUnpaid,
            externalPrepaymentEndingCount: count($externalPrepaymentEnding),
            externalPrepaymentEnding: $externalPrepaymentEnding,
            overdueCount: $overdueSummary->count,
            overdueAmount: $overdueSummary->totalAmount,
            totalPending: $totalPending,
        );
    }

    /**
     * Scalar-SQL count for the sidebar badge / dashboard tile.
     * Mirrors OverdueExtension::overdueCount() — no hydration.
     */
    public function totalPendingCount(\DateTimeImmutable $now): int
    {
        return $this->handoverProtocolRepository->countPending()
            + $this->contractRepository->countExpiringWithoutProtocol(self::CONTRACTS_ENDING_WINDOW_DAYS, $now)
            + $this->orderRepository->countUnpaidSignedOnboarding($now)
            + $this->contractRepository->countExternalPrepaymentEndingWithinDays(self::EXTERNAL_PREPAYMENT_WINDOW_DAYS, $now);
    }
}
