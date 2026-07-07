<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Order;
use App\Entity\Place;

/**
 * Value object holding the five manual-billing reminder offsets for one
 * scope (a Place's current settings, or an Order's snapshot). Constructed
 * via {@see self::fromOrder()} / {@see self::fromPlace()} — no DI wiring.
 */
final readonly class ManualBillingReminderSchedule
{
    public const string STAGE_INITIAL = 'initial';
    public const string STAGE_REMINDER = 'd_minus_2';
    public const string STAGE_FINAL_DUE = 'd_zero';
    public const string STAGE_OVERDUE_FIRST = 'd_plus_3';
    public const string STAGE_OVERDUE_FINAL = 'd_plus_7';

    public function __construct(
        public int $offsetInitial,
        public int $offsetReminder,
        public int $offsetFinalDue,
        public int $offsetOverdueFirst,
        public int $offsetOverdueFinal,
    ) {
    }

    public static function fromOrder(Order $order): self
    {
        return new self(
            $order->manualBillingOffsetInitial,
            $order->manualBillingOffsetReminder,
            $order->manualBillingOffsetFinalDue,
            $order->manualBillingOffsetOverdueFirst,
            $order->manualBillingOffsetOverdueFinal,
        );
    }

    public static function fromPlace(Place $place): self
    {
        return new self(
            $place->manualBillingOffsetInitial,
            $place->manualBillingOffsetReminder,
            $place->manualBillingOffsetFinalDue,
            $place->manualBillingOffsetOverdueFirst,
            $place->manualBillingOffsetOverdueFinal,
        );
    }

    /**
     * @return array<string, int>
     */
    public function stages(): array
    {
        return [
            self::STAGE_INITIAL => $this->offsetInitial,
            self::STAGE_REMINDER => $this->offsetReminder,
            self::STAGE_FINAL_DUE => $this->offsetFinalDue,
            self::STAGE_OVERDUE_FIRST => $this->offsetOverdueFirst,
            self::STAGE_OVERDUE_FINAL => $this->offsetOverdueFinal,
        ];
    }

    /**
     * Return the stage whose offset "bracket" covers today: the stage with
     * the GREATEST offset <= today's calendar-day diff from $nextBillingDate
     * (truncated to midnight so a DST shift or a midnight cron run hits the
     * intended day). Returns null before the earliest offset day.
     *
     * On an uninterrupted daily-cron timeline this behaves exactly like an
     * exact-day match: each stage first becomes "current" on its own offset
     * day. The bracket semantics matter when a contract ENTERS the manual
     * track late — onboarded close to (or past) the due date, repaired by a
     * data migration, or after a cron outage. Exact matching silently
     * skipped every stage whose day had already passed, so such a customer
     * received no payment request at all (and could be overdue-terminated
     * without a single e-mail). With brackets, the next cron run sends the
     * ONE currently-applicable stage; earlier stages are never dispatched
     * (their bracket has passed) and the handler's sentStages gate keeps
     * every stage at most-once — a late entry never gets a burst of
     * stacked reminders.
     */
    public function dueStageOn(\DateTimeImmutable $now, \DateTimeImmutable $nextBillingDate): ?string
    {
        $today = $now->setTime(0, 0, 0);
        $anchor = $nextBillingDate->setTime(0, 0, 0);
        $diffDays = (int) $today->diff($anchor)->format('%r%a');
        $offsetToday = -$diffDays;

        $currentStage = null;
        $currentOffset = null;

        foreach ($this->stages() as $stage => $offset) {
            if ($offset <= $offsetToday && (null === $currentOffset || $offset > $currentOffset)) {
                $currentStage = $stage;
                $currentOffset = $offset;
            }
        }

        return $currentStage;
    }

    /**
     * Inclusive bounds of the offset set — used by the cron's SQL pre-filter
     * to bound the nextBillingDate window for any contract before refining
     * the stage decision in PHP.
     *
     * @return array{int, int} [minOffset, maxOffset]
     */
    public function offsetBounds(): array
    {
        // stages() always returns 5 entries — the literal list below makes
        // PHPStan happy without a runtime cost.
        $offsets = [
            $this->offsetInitial,
            $this->offsetReminder,
            $this->offsetFinalDue,
            $this->offsetOverdueFirst,
            $this->offsetOverdueFinal,
        ];

        return [min($offsets), max($offsets)];
    }
}
