<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Contract;

/**
 * Wording of the post-payment "rental activated" e-mail.
 *
 * Every date the customer reads here comes from {@see Contract::lastPaidDay()}
 * / {@see Contract::isPaidToEndOfTerm()} — never from paidThroughDate raw,
 * which for customer-paid cycles is the first UNPAID day ("uhrazeno do X.
 * Od X …" with the same day twice). A rental that starts in the future is
 * not announced as "zahájen".
 */
final readonly class RentalActivatedEmailContent
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public string $title,
        public string $subject,
        public string $headline,
        public string $body,
        public ?\DateTimeImmutable $paidThroughDate,
    ) {
    }

    /**
     * @param int|null $nextCycleAmount amount in haléře of the next billing cycle
     *                                  (prorated when it is the last one), as
     *                                  RecurringAmountCalculator computes it
     */
    public static function fromContract(Contract $contract, \DateTimeImmutable $now, ?int $nextCycleAmount = null): self
    {
        $situation = CustomerBillingSituation::fromContract($contract);
        $placeName = $contract->storage->getPlace()->name;
        $startsInFuture = $contract->startDate->format('Y-m-d') > $now->format('Y-m-d');
        $startFormatted = $contract->startDate->format('d.m.Y');
        $title = $startsInFuture ? 'Pronájem začne '.$startFormatted : 'Pronájem zahájen';

        return match ($situation) {
            CustomerBillingSituation::GOPAY_FIRST_CHARGE => new self(
                situation: $situation,
                title: $title,
                subject: $title.' — platba přijata — '.$placeName,
                headline: 'Vaše platba byla úspěšně zpracována — '.($startsInFuture
                    ? 'pronájem skladu začne '.$startFormatted
                    : 'pronájem skladu je aktivní'),
                body: self::customerPaidBody($contract, $nextCycleAmount),
                paidThroughDate: $contract->lastPaidDay(),
            ),
            CustomerBillingSituation::EXTERNALLY_PREPAID => new self(
                situation: $situation,
                title: $title,
                subject: sprintf('%s — uhrazeno do %s — %s', $title, self::paidUntilShort($contract), $placeName),
                headline: $startsInFuture ? 'Smlouva je podepsána — pronájem začne '.$startFormatted : 'Pronájem byl zahájen',
                body: self::externallyPrepaidBody($contract),
                paidThroughDate: $contract->lastPaidDay(),
            ),
            CustomerBillingSituation::FREE => new self(
                situation: $situation,
                title: $title,
                subject: $title.' — bezplatný pronájem — '.$placeName,
                headline: ($startsInFuture ? 'Smlouva je podepsána' : 'Pronájem byl zahájen').' — bezplatný pronájem',
                body: 'U této smlouvy se neúčtuje žádné měsíční nájemné. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.',
                paidThroughDate: null,
            ),
        };
    }

    /**
     * The customer paid the first charge themselves — by card (a recurring
     * token now exists) or by bank transfer (manual track, or a one-shot).
     */
    private static function customerPaidBody(Contract $contract, ?int $nextCycleAmount): string
    {
        $isCard = null !== $contract->goPayParentPaymentId;
        $documents = $isCard
            ? 'V příloze tohoto e-mailu najdete kompletní sadu dokumentů — podepsanou smlouvu, všeobecné obchodní podmínky, poučení spotřebitele a u opakovaných plateb i podmínky opakovaných plateb.'
            : 'V příloze tohoto e-mailu najdete kompletní sadu dokumentů — podepsanou smlouvu, všeobecné obchodní podmínky a poučení spotřebitele.';

        $billing = '';
        $lastPaidDay = $contract->lastPaidDay();
        if ($contract->isPaidToEndOfTerm()) {
            $billing = sprintf(' Nájemné je uhrazeno do konce smlouvy (%s) — žádné další platby Vás nečekají.', $contract->getEffectiveEndDate()->format('d.m.Y'));
        } elseif (null !== $lastPaidDay && null !== $contract->nextBillingDate) {
            $billing = sprintf(' Nájemné je uhrazeno do %s.', $lastPaidDay->format('d.m.Y'));
            if (!$isCard && $contract->usesManualBillingTrack()) {
                $billing .= sprintf(
                    // No "N dní předem" here: after a late first payment the next
                    // due date can be closer than the reminder offset.
                    ' Další platba%s je splatná %s — před splatností Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod.',
                    null !== $nextCycleAmount ? sprintf(' ve výši %s Kč (vč. DPH)', self::formatCzk($nextCycleAmount)) : '',
                    $contract->nextBillingDate->format('d.m.Y'),
                );
            }
        }

        return 'Děkujeme za Vaši platbu.'.$billing.' '.$documents;
    }

    private static function externallyPrepaidBody(Contract $contract): string
    {
        $lastPaidDay = $contract->lastPaidDay();

        if (null === $contract->nextBillingDate || null === $lastPaidDay) {
            return sprintf(
                'Nájemné máte uhrazené do konce smlouvy (%s) — žádné další platby Vás nečekají. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.',
                $contract->getEffectiveEndDate()->format('d.m.Y'),
            );
        }

        return sprintf(
            'Nájemné máte uhrazené do %s. Od %s činí nájemné %s Kč / %s (vč. DPH) — před každou splatností (%s předem) Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.',
            $lastPaidDay->format('d.m.Y'),
            $contract->nextBillingDate->format('d.m.Y'),
            self::formatCzk($contract->getEffectiveRecurringAmount()),
            $contract->isYearly() ? 'rok' : 'měsíc',
            self::formatDaysCzech(abs($contract->order->manualBillingOffsetInitial)),
        );
    }

    private static function paidUntilShort(Contract $contract): string
    {
        $lastPaidDay = $contract->lastPaidDay();

        return null !== $contract->nextBillingDate && null !== $lastPaidDay
            ? $lastPaidDay->format('d.m.Y')
            : 'konce smlouvy';
    }

    private static function formatCzk(int $haler): string
    {
        return number_format($haler / 100, 0, ',', ' ');
    }

    private static function formatDaysCzech(int $days): string
    {
        return $days.' '.match (true) {
            1 === $days => 'den',
            $days <= 4 => 'dny',
            default => 'dní',
        };
    }
}
