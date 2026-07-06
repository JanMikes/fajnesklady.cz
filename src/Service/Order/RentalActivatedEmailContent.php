<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Contract;

final readonly class RentalActivatedEmailContent
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public string $subject,
        public string $headline,
        public string $body,
        public ?\DateTimeImmutable $paidThroughDate,
    ) {
    }

    public static function fromContract(Contract $contract): self
    {
        $situation = CustomerBillingSituation::fromContract($contract);
        $placeName = $contract->storage->getPlace()->name;

        return match ($situation) {
            CustomerBillingSituation::GOPAY_FIRST_CHARGE => new self(
                situation: $situation,
                subject: 'Pronájem zahájen — platba zpracována — '.$placeName,
                headline: 'Vaše platba byla úspěšně zpracována — pronájem skladu je aktivní',
                body: 'Děkujeme za Vaši platbu. V příloze tohoto e-mailu najdete kompletní sadu dokumentů — podepsanou smlouvu, všeobecné obchodní podmínky, poučení spotřebitele a u opakovaných plateb i podmínky opakovaných plateb.',
                paidThroughDate: null,
            ),
            CustomerBillingSituation::EXTERNALLY_PREPAID => new self(
                situation: $situation,
                subject: sprintf('Pronájem zahájen — předplaceno do %s — %s', $contract->paidThroughDate?->format('d.m.Y') ?? '', $placeName),
                headline: 'Pronájem byl zahájen',
                body: self::externallyPrepaidBody($contract),
                paidThroughDate: $contract->paidThroughDate,
            ),
            CustomerBillingSituation::FREE => new self(
                situation: $situation,
                subject: 'Pronájem zahájen — bezplatný pronájem — '.$placeName,
                headline: 'Pronájem byl zahájen — bezplatný pronájem',
                body: 'U této smlouvy se neúčtuje žádné měsíční nájemné. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.',
                paidThroughDate: null,
            ),
        };
    }

    /**
     * markExternallyPrepaid() leaves nextBillingDate null exactly when the
     * prepayment covers the whole term — nothing will ever be billed.
     */
    private static function externallyPrepaidBody(Contract $contract): string
    {
        $paidThroughFormatted = $contract->paidThroughDate?->format('d.m.Y') ?? '';

        if (null === $contract->nextBillingDate) {
            return sprintf(
                'Pronájem je předplacen externě do konce smlouvy (%s) — žádné další platby Vás nečekají. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.',
                $paidThroughFormatted,
            );
        }

        return sprintf(
            'Pronájem je předplacen externě do %s. Od %s činí nájemné %s Kč / %s (vč. DPH) — před každou splatností (%s předem) Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.',
            $paidThroughFormatted,
            $contract->nextBillingDate->format('d.m.Y'),
            number_format($contract->getEffectiveRecurringAmount() / 100, 0, ',', ' '),
            $contract->isYearly() ? 'rok' : 'měsíc',
            self::formatDaysCzech(abs($contract->order->manualBillingOffsetInitial)),
        );
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
