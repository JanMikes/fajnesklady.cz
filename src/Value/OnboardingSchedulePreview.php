<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Everything the admin-onboarding "Kalkulace plateb" card renders — computed
 * from the CURRENT form values so the preview always mirrors the contract the
 * submit would create: pricing mode (standard / individuální / zdarma),
 * payment frequency, payment method and external prepayment.
 */
final readonly class OnboardingSchedulePreview
{
    /**
     * @param ?PaymentSchedule    $schedule                the charges to list; null when nothing
     *                                                     will be charged (free / fully prepaid)
     * @param bool                $isFree                  "Zdarma" pricing mode — no charges ever
     * @param bool                $isFullyPrepaid          external prepayment covers the whole rental
     * @param ?\DateTimeImmutable $prepaidUntil            paid-through date, when external prepayment
     *                                                     (or a backdated start) is in play
     * @param bool                $isAnchoredAtPaidThrough the schedule starts AT $prepaidUntil (the
     *                                                     covered period is omitted); false means the
     *                                                     full schedule is shown and $prepaidUntil is
     *                                                     only informative
     * @param ?string             $customPriceFallback     why the individual price is NOT reflected:
     *                                                     'missing' (not entered yet) or
     *                                                     'upfront_tranches' (custom total unavailable
     *                                                     for > 12-month upfront rentals); null when
     *                                                     applied or not in custom mode
     */
    public function __construct(
        public ?PaymentSchedule $schedule,
        public bool $isFree = false,
        public bool $isFullyPrepaid = false,
        public ?\DateTimeImmutable $prepaidUntil = null,
        public bool $isAnchoredAtPaidThrough = false,
        public ?string $customPriceFallback = null,
    ) {
    }
}
