<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\PriceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Two-walk billing (2026-11-25): rental periods follow the contract start (the
 * quoted schedule), every later payment is due on the first payment's day of
 * month ("vždy ke stejnému dni v měsíci jako první platba" — every payment
 * type), and the last one pays only the days left. Walks whole contracts the
 * way the billing crons do and checks, for every combination of payment
 * timing, start day, length and cadence, that:
 *  - the customer pays exactly the quoted schedule, entry by entry;
 *  - each payment is due on the first payment's day (clamped in short months)
 *    — except a late payer's last, short period, due on its first day;
 *  - due dates strictly increase and never reach the end; an early payer is
 *    always due on or before the period starts, a late payer on or after.
 */
class TwoWalkBillingPropertyTest extends TestCase
{
    private const array PAYMENT_OFFSETS = [-27, -20, -15, -14, -8, -3, -1, 0, 1, 2, 5, 13, 14, 15, 16, 27, 28, 29, 30, 45, 61, 90, 200];

    private PriceCalculator $priceCalculator;
    private RecurringAmountCalculator $amountCalculator;
    private Storage $storage;
    private User $user;

    protected function setUp(): void
    {
        $this->priceCalculator = new PriceCalculator();
        $this->amountCalculator = new RecurringAmountCalculator($this->priceCalculator);

        $now = new \DateTimeImmutable('2026-01-01');
        $place = new Place(Uuid::v7(), 'Sklad', 'Adresa 1', 'Praha', '110 00', null, $now);
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 55_000,
            defaultPricePerMonth: 175_000,
            defaultPricePerMonthLongTerm: 150_000,
            defaultPricePerYear: 1_620_000,
            createdAt: $now,
        );
        $this->storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
        $this->user = new User(Uuid::v7(), 'tenant@example.com', 'pw', 'Jan', 'Novák', $now);
    }

    /**
     * @return iterable<string, array{PaymentFrequency, string}>
     */
    public static function startDays(): iterable
    {
        foreach (['2026-01-01', '2026-01-15', '2026-01-29', '2026-01-31', '2027-02-28', '2027-11-30', '2028-02-29'] as $start) {
            yield 'monthly from '.$start => [PaymentFrequency::MONTHLY, $start];
            yield 'yearly from '.$start => [PaymentFrequency::YEARLY, $start];
        }
    }

    #[DataProvider('startDays')]
    public function testEveryContractPaysExactlyTheQuotedScheduleOnTheFirstPaymentDay(PaymentFrequency $frequency, string $startString): void
    {
        $start = new \DateTimeImmutable($startString);
        $checked = 0;

        foreach (self::PAYMENT_OFFSETS as $offset) {
            for ($months = 1; $months <= 25; ++$months) {
                foreach ([0, 9] as $tailDays) {
                    if (PaymentFrequency::YEARLY === $frequency && $months < 12) {
                        continue;
                    }
                    $end = $start->modify(sprintf('+%d months +%d days', $months, $tailDays));
                    $this->assertContractMatchesSchedule($frequency, $start, $end, $start->modify(sprintf('%+d days', -$offset)));
                    ++$checked;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * The 2026-11-24 incident shape: a 12-month yearly rental paid a week
     * early is fully paid by its one payment — nothing more is ever due.
     */
    public function testYearlyTwelveMonthsPaidAWeekEarlyNeedsNoFurtherPayment(): void
    {
        $contract = $this->contract(PaymentFrequency::YEARLY, new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2027-12-01'));

        $contract->startBilling(new \DateTimeImmutable('2026-11-24 17:45:27'));

        $this->assertNull($contract->nextBillingDate);
        $this->assertTrue($contract->isPaidToEndOfTerm());
    }

    /**
     * Prod contract VS 2185421194 shape: card paid 21.07 for a 01.08 – 01.05
     * rental (9 months, 13 500 Kč). Charges fall on the 21st; the last one
     * (21.03) pays 01.04 – 01.05 in full — no 21.04 stub.
     */
    public function testCardPaidElevenDaysEarlyIsChargedOnThe21stAndNeverPastTheEnd(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2027-05-01'));
        $contract->startBilling(new \DateTimeImmutable('2026-07-21 09:15:48'));

        $charges = [];
        $total = 150_000;
        while (null !== $contract->nextBillingDate) {
            $due = $contract->nextBillingDate;
            $amount = $this->amountCalculator->calculate($contract, $due);
            $cycle = $contract->recordCyclePaid($due);
            $charges[] = sprintf('%s %d (%s–%s)', $due->format('d.m.Y'), $amount / 100, $cycle->start->format('d.m'), $cycle->end->format('d.m'));
            $total += $amount;
        }

        $this->assertSame([
            '21.08.2026 1500 (01.09–01.10)',
            '21.09.2026 1500 (01.10–01.11)',
            '21.10.2026 1500 (01.11–01.12)',
            '21.11.2026 1500 (01.12–01.01)',
            '21.12.2026 1500 (01.01–01.02)',
            '21.01.2027 1500 (01.02–01.03)',
            '21.02.2027 1500 (01.03–01.04)',
            '21.03.2027 1500 (01.04–01.05)',
        ], $charges);
        $this->assertSame(1_350_000, $total);
        $this->assertSame('2027-05-01', $contract->paidThroughDate?->format('Y-m-d'));
    }

    /**
     * The production back-fill (backfill_two_walk.sql) for VS 2185421194: a
     * legacy single-walk card contract (anchored on the 21st, 5 periods paid)
     * gets its period pointer set to 01.01.2027 — the remaining charges stay
     * on the 21st and the last one pays 01.04 – 01.05 in full (13 500 Kč
     * total instead of 14 000).
     */
    public function testBackfilledLegacyCardContractFinishesExactlyAtTheEnd(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2027-05-01'));
        $contract->setRecurringPayment('gp_parent', new \DateTimeImmutable('2026-12-21 09:15:48'), new \DateTimeImmutable('2026-12-21'));
        foreach ([
            'nextPeriodStart' => new \DateTimeImmutable('2027-01-01'),
            'periodAnchorDay' => 1,
            'chargeMonthOffset' => 1,
            'paidThroughDate' => new \DateTimeImmutable('2027-01-01'),
        ] as $property => $value) {
            new \ReflectionProperty(Contract::class, $property)->setValue($contract, $value);
        }

        $charges = [];
        while (null !== $contract->nextBillingDate) {
            $due = $contract->nextBillingDate;
            $charges[] = $due->format('d.m.Y').' '.($this->amountCalculator->calculate($contract, $due) / 100);
            $contract->recordCyclePaid($due);
        }

        $this->assertSame(['21.12.2026 1500', '21.01.2027 1500', '21.02.2027 1500', '21.03.2027 1500'], $charges);
        $this->assertSame('2027-05-01', $contract->paidThroughDate?->format('Y-m-d'));
    }

    /**
     * Fable review 2026-11-25 #1: an early payer (due 21.12 for the period
     * from 01.01) gives notice ending 26.12 — the next period lies wholly
     * after the end: nothing more may be billed.
     */
    public function testNoticeEndingBeforeTheNextPeriodStopsBilling(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2027-05-01'));
        $contract->startBilling(new \DateTimeImmutable('2026-07-21 09:00'));
        for ($i = 0; $i < 4; ++$i) { // 21.08, 21.09, 21.10, 21.11
            $due = $contract->nextBillingDate;
            $this->assertNotNull($due);
            $contract->recordCyclePaid($due);
        }
        $this->assertSame('2026-12-21', $contract->nextBillingDate?->format('Y-m-d'));
        $this->assertSame('2027-01-01', $contract->nextPeriodStart()?->format('Y-m-d'));

        $contract->requestTermination(new \DateTimeImmutable('2026-11-26 10:00'), new \DateTimeImmutable('2026-12-26 10:00'));

        $this->assertNull($contract->nextBillingDate, 'the period from 01.01 is after the end');
        $this->assertSame('2027-01-01', $contract->paidThroughDate?->format('Y-m-d'));
    }

    /**
     * Fable review #2: a late payer's last, shortened period must still be
     * billed when the notice ends on its due day (terminatesAt has a time).
     */
    public function testLatePayerNoticeEndingOnTheDueDayStillBillsTheShortenedPeriod(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2027-06-01'));
        $contract->startBilling(new \DateTimeImmutable('2026-06-05 10:00'));
        $this->assertSame('2026-07-05', $contract->nextBillingDate?->format('Y-m-d'));

        $contract->requestTermination(new \DateTimeImmutable('2026-06-05 06:00'), new \DateTimeImmutable('2026-07-05 06:00'));

        $this->assertSame('2026-07-01', $contract->nextBillingDate?->format('Y-m-d'), 'due on the period start, before the end');
        // 01.07 – 05.07 = 4 days at the contract's 6+ month rate: 1 500 / 30 × 4.
        $this->assertSame(20_000, $this->amountCalculator->calculate($contract, new \DateTimeImmutable('2026-07-01')));
    }

    /**
     * Fable review #3: an admin extension never moves the termination
     * countdown below the end of the already-paid period.
     */
    public function testGraceNeverShortensTheTerminationCountdownBelowThePaidPeriod(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2027-05-01'));
        $contract->startBilling(new \DateTimeImmutable('2026-07-21 09:00'));
        $this->assertSame('2026-08-21', $contract->nextBillingDate?->format('Y-m-d'));

        $this->assertSame('2026-09-01', $contract->terminationCountdownAnchor()?->format('Y-m-d'));

        $contract->extendPaymentDeadline(new \DateTimeImmutable('2026-08-23'), new \DateTimeImmutable('2026-08-22'));
        $this->assertSame('2026-09-01', $contract->terminationCountdownAnchor()?->format('Y-m-d'));
    }

    /**
     * A notice whose one-month period runs past the contract end never
     * stretches the rental or its billing (prod 2026-11-25: notice → 25.12 on
     * a contract ending 21.12).
     */
    public function testNoticePastTheContractEndDoesNotExtendBilling(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2026-11-13'), new \DateTimeImmutable('2026-12-21'));
        $contract->startBilling(new \DateTimeImmutable('2026-11-10 12:28'));

        $contract->requestTermination(new \DateTimeImmutable('2026-11-25 15:14'), new \DateTimeImmutable('2026-12-25 15:14'));

        $this->assertSame('2026-12-21', $contract->getEffectiveEndDate()->format('Y-m-d'));
        $this->assertSame('2026-12-10', $contract->nextBillingDate?->format('Y-m-d'));
        // 13.12 – 21.12 = 8 days of the 1–6 month rate (1 750 Kč / 30).
        $this->assertSame(46_700, $this->amountCalculator->calculate($contract, new \DateTimeImmutable('2026-12-10')));
    }

    /**
     * Fable re-review #3: an admin prepayment through 31.12 (inclusive) with a
     * notice ending 01.01 — fully paid, nothing unscheduled, no billing.
     */
    public function testNoticeOnAdminPrepaidContractEndsFullyPaid(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2027-05-01'));
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-12-31'));
        $this->assertSame('2027-01-01', $contract->nextBillingDate?->format('Y-m-d'));

        $contract->requestTermination(new \DateTimeImmutable('2026-12-01 10:00'), new \DateTimeImmutable('2027-01-01 10:00'));

        $this->assertNull($contract->nextBillingDate);
        $this->assertTrue($contract->isPaidToEndOfTerm());
        $this->assertFalse($contract->hasUnscheduledUnpaidPeriod());
        $this->assertFalse($contract->isEligibleForCardSetup(new \DateTimeImmutable('2026-12-02')), 'nothing left for a card-setup charge to pay');
    }

    /**
     * An order placed months ahead: the next charge comes right before the
     * second rental period, not one month after the payment.
     */
    public function testOrderPaidMonthsAheadIsNextChargedRightBeforeTheSecondPeriod(): void
    {
        $contract = $this->contract(PaymentFrequency::MONTHLY, new \DateTimeImmutable('2027-03-13'), new \DateTimeImmutable('2027-09-13'));

        $contract->startBilling(new \DateTimeImmutable('2026-11-10'));

        $this->assertSame('2027-04-10', $contract->nextBillingDate?->format('Y-m-d'));
        $this->assertSame('2027-04-13', $contract->nextPeriodStart()?->format('Y-m-d'));
        $this->assertSame('2027-04-12', $contract->lastPaidDay()?->format('Y-m-d'));
    }

    private function assertContractMatchesSchedule(PaymentFrequency $frequency, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $firstPaidAt): void
    {
        $case = sprintf('%s %s–%s paid %s', $frequency->value, $start->format('Y-m-d'), $end->format('Y-m-d'), $firstPaidAt->format('Y-m-d'));
        $schedule = $this->priceCalculator->buildPaymentSchedule($this->storage, $start, $end, $frequency);
        $contract = $this->contract($frequency, $start, $end);

        $contract->startBilling($firstPaidAt->setTime(14, 30));
        $chargeDay = (int) $firstPaidAt->format('j');
        $paidEarly = $firstPaidAt <= $start;
        $previousDue = $firstPaidAt;

        $entries = $schedule->entries;
        for ($i = 1, $n = count($entries); $i < $n; ++$i) {
            $due = $contract->nextBillingDate;
            $this->assertNotNull($due, $case.": cycle {$i} missing");
            $periodStart = $contract->nextPeriodStart();
            $this->assertNotNull($periodStart);

            $this->assertSame($entries[$i]->chargeDate->format('Y-m-d'), $periodStart->format('Y-m-d'), $case.": period {$i}");
            $this->assertSame($entries[$i]->amount, $this->amountCalculator->calculate($contract, $due), $case.": amount {$i}");
            $this->assertGreaterThan($previousDue->format('Y-m-d'), $due->format('Y-m-d'), $case.": due {$i} must follow the previous one");
            $this->assertLessThan($end->format('Y-m-d'), $due->format('Y-m-d'), $case.": due {$i} before the end");

            $isLatePayersLastPeriod = !$paidEarly && $due->format('Y-m-d') === $periodStart->format('Y-m-d');
            if (!$isLatePayersLastPeriod) {
                $this->assertSame(min($chargeDay, (int) $due->format('t')), (int) $due->format('j'), $case.": due {$i} on the first payment's day");
            }
            if ($paidEarly) {
                $this->assertLessThanOrEqual($periodStart->format('Y-m-d'), $due->format('Y-m-d'), $case.": early payer due {$i} not after its period starts");
            } else {
                $this->assertGreaterThanOrEqual($periodStart->format('Y-m-d'), $due->format('Y-m-d'), $case.": late payer due {$i} not before its period starts");
            }

            $previousDue = $due;
            $contract->recordCyclePaid($due->setTime(9, 0));
        }

        $this->assertNull($contract->nextBillingDate, $case.': nothing billed beyond the schedule');
        $this->assertSame($end->format('Y-m-d'), $contract->paidThroughDate?->format('Y-m-d'), $case.': paid to the end');
    }

    private function contract(PaymentFrequency $frequency, \DateTimeImmutable $start, \DateTimeImmutable $end): Contract
    {
        $order = new Order(
            id: Uuid::v7(),
            user: $this->user,
            storage: $this->storage,
            paymentFrequency: $frequency,
            startDate: $start,
            endDate: $end,
            firstPaymentPrice: 150_000,
            expiresAt: $start,
            createdAt: $start,
        );

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $this->user,
            storage: $this->storage,
            startDate: $start,
            endDate: $end,
            createdAt: $start,
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->applyPaymentFrequency($frequency);

        return $contract;
    }
}
