<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Overdue;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Enum\TerminationReason;
use App\Repository\ContractRepository;
use App\Service\Overdue\OverdueChecker;
use App\Value\OverdueSeverity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

class OverdueCheckerTest extends TestCase
{
    private const MONTHLY_RATE = 120000;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2025-06-15 12:00:00 UTC');
    }

    public function testActiveWithoutAttemptsAndOverdueNextBillingProducesWarning(): void
    {
        $now = $this->clock->now();
        $contract = $this->createRecurringContract($now->modify('-3 days'));

        $checker = $this->createChecker([$contract]);
        $views = $checker->findOverdueViews($now);

        $this->assertCount(1, $views);
        $this->assertSame(OverdueSeverity::WARNING, $views[0]->severity);
        $this->assertSame(3, $views[0]->daysOverdue);
        $this->assertSame(self::MONTHLY_RATE, $views[0]->overdueAmount);
        $this->assertSame('Strhnutí splatné', $views[0]->reasonLabel);
    }

    public function testOneFailedAttemptProducesError(): void
    {
        $now = $this->clock->now();
        $contract = $this->createRecurringContract($now->modify('-5 days'));
        $contract->recordFailedBillingAttempt($now->modify('-3 days'));

        $checker = $this->createChecker([$contract]);
        $views = $checker->findOverdueViews($now);

        $this->assertCount(1, $views);
        $this->assertSame(OverdueSeverity::ERROR, $views[0]->severity);
        $this->assertSame(5, $views[0]->daysOverdue);
        $this->assertSame(self::MONTHLY_RATE, $views[0]->overdueAmount);
        $this->assertSame('Selhání platby (1×)', $views[0]->reasonLabel);
    }

    public function testTwoFailedAttemptsProduceErrorWithReasonCount(): void
    {
        $now = $this->clock->now();
        $contract = $this->createRecurringContract($now->modify('-12 days'));
        $contract->recordFailedBillingAttempt($now->modify('-9 days'));
        $contract->recordFailedBillingAttempt($now->modify('-2 days'));

        $checker = $this->createChecker([$contract]);
        $views = $checker->findOverdueViews($now);

        $this->assertCount(1, $views);
        $this->assertSame(OverdueSeverity::ERROR, $views[0]->severity);
        $this->assertSame(12, $views[0]->daysOverdue);
        $this->assertSame(self::MONTHLY_RATE, $views[0]->overdueAmount); // retries don't accrue periods
        $this->assertSame('Selhání platby (2×)', $views[0]->reasonLabel);
    }

    public function testTerminatedWithDebtProducesCritical(): void
    {
        $now = $this->clock->now();
        $contract = $this->createRecurringContract($now->modify('-30 days'));
        $contract->setOutstandingDebt(350000);
        $contract->terminate($now->modify('-15 days'), TerminationReason::PAYMENT_FAILURE);

        $checker = $this->createChecker([$contract]);
        $views = $checker->findOverdueViews($now);

        $this->assertCount(1, $views);
        $this->assertSame(OverdueSeverity::CRITICAL, $views[0]->severity);
        $this->assertSame(15, $views[0]->daysOverdue);
        $this->assertSame(350000, $views[0]->overdueAmount);
        $this->assertSame('Dluh — smlouva ukončena', $views[0]->reasonLabel);
    }

    public function testSortOrderCriticalBeforeErrorBeforeWarningThenDaysDesc(): void
    {
        $now = $this->clock->now();

        $warning = $this->createRecurringContract($now->modify('-3 days'));

        $errorRecent = $this->createRecurringContract($now->modify('-5 days'));
        $errorRecent->recordFailedBillingAttempt($now->modify('-3 days'));

        $errorOld = $this->createRecurringContract($now->modify('-12 days'));
        $errorOld->recordFailedBillingAttempt($now->modify('-9 days'));

        $criticalOld = $this->createRecurringContract($now->modify('-90 days'));
        $criticalOld->setOutstandingDebt(500000);
        $criticalOld->terminate($now->modify('-40 days'), TerminationReason::PAYMENT_FAILURE);

        $criticalRecent = $this->createRecurringContract($now->modify('-90 days'));
        $criticalRecent->setOutstandingDebt(300000);
        $criticalRecent->terminate($now->modify('-10 days'), TerminationReason::PAYMENT_FAILURE);

        // Pass in a deliberately scrambled order to verify sort.
        $checker = $this->createChecker([$warning, $errorRecent, $criticalRecent, $errorOld, $criticalOld]);
        $views = $checker->findOverdueViews($now);

        $this->assertCount(5, $views);
        $this->assertSame(OverdueSeverity::CRITICAL, $views[0]->severity);
        $this->assertSame(40, $views[0]->daysOverdue);
        $this->assertSame(OverdueSeverity::CRITICAL, $views[1]->severity);
        $this->assertSame(10, $views[1]->daysOverdue);
        $this->assertSame(OverdueSeverity::ERROR, $views[2]->severity);
        $this->assertSame(12, $views[2]->daysOverdue);
        $this->assertSame(OverdueSeverity::ERROR, $views[3]->severity);
        $this->assertSame(5, $views[3]->daysOverdue);
        $this->assertSame(OverdueSeverity::WARNING, $views[4]->severity);
    }

    public function testSummariseSumsAndTopFiveOnly(): void
    {
        $now = $this->clock->now();
        $contracts = [];
        for ($i = 1; $i <= 7; ++$i) {
            $contract = $this->createRecurringContract($now->modify("-{$i} days"));
            $contract->recordFailedBillingAttempt($now->modify('-1 day'));
            $contracts[] = $contract;
        }

        $checker = $this->createChecker($contracts);
        $summary = $checker->summarise($now);

        $this->assertSame(7, $summary->count);
        $this->assertSame(7 * self::MONTHLY_RATE, $summary->totalAmount);
        $this->assertCount(5, $summary->top);
    }

    public function testFilterOverdueUserIdsShortCircuitsOnEmptyInput(): void
    {
        $repository = $this->createMock(ContractRepository::class);
        $repository->expects($this->never())->method('findOverdueUserIds');

        $checker = new OverdueChecker($repository);
        $this->assertSame([], $checker->filterOverdueUserIds($this->clock->now(), []));
    }

    /**
     * @param Contract[] $contracts
     */
    private function createChecker(array $contracts): OverdueChecker
    {
        $repository = $this->createMock(ContractRepository::class);
        $repository->method('findWithPaymentIssues')->willReturn($contracts);

        return new OverdueChecker($repository);
    }

    private function createRecurringContract(\DateTimeImmutable $nextBillingDate): Contract
    {
        $createdAt = new \DateTimeImmutable('2025-01-01');
        $user = new User(Uuid::v7(), 'overdue-test@example.com', 'password', 'Test', 'User', $createdAt);
        $place = new Place(Uuid::v7(), 'Place', 'Address', 'City', '00000', null, $createdAt);
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, self::MONTHLY_RATE, $createdAt);
        $storage = new Storage(
            Uuid::v7(),
            '1',
            ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            $storageType,
            $place,
            $createdAt,
        );
        $order = new Order(
            Uuid::v7(),
            $user,
            $storage,
            RentalType::UNLIMITED,
            PaymentFrequency::MONTHLY,
            $createdAt,
            null,
            self::MONTHLY_RATE,
            $createdAt->modify('+7 days'),
            $createdAt,
        );

        $contract = new Contract(
            Uuid::v7(),
            $order,
            $user,
            $storage,
            RentalType::UNLIMITED,
            $createdAt,
            null,
            $createdAt,
        );
        $contract->setRecurringPayment('gopay-parent-id', $nextBillingDate, $nextBillingDate);

        return $contract;
    }
}
