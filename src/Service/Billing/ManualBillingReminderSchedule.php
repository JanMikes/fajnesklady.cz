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
     * Return the stage whose offset matches today's calendar-day diff from
     * $nextBillingDate. Compare in calendar days (truncated to midnight) so
     * a DST shift or a midnight cron run hits the intended day. Returns null
     * when today does not match any stage offset.
     */
    public function dueStageOn(\DateTimeImmutable $now, \DateTimeImmutable $nextBillingDate): ?string
    {
        $today = $now->setTime(0, 0, 0);
        $anchor = $nextBillingDate->setTime(0, 0, 0);
        $diffDays = (int) $today->diff($anchor)->format('%r%a');
        $offsetToday = -$diffDays;

        foreach ($this->stages() as $stage => $offset) {
            if ($offset === $offsetToday) {
                return $stage;
            }
        }

        return null;
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
