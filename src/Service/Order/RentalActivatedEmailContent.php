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
                body: sprintf(
                    'Pronájem je předplacen externě do %s. Po vypršení předplatného Vás kontaktujeme s pokyny pro další platby. V příloze tohoto e-mailu najdete podepsanou smlouvu a všechny související dokumenty.',
                    $contract->paidThroughDate?->format('d.m.Y') ?? '',
                ),
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
}
