<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Order;

final readonly class SigningEmailContent
{
    public function __construct(
        public CustomerBillingSituation $situation,
        public string $subject,
        public string $headline,
        public string $nextStepLine,
        public string $buttonLabel,
        public ?\DateTimeImmutable $paidThroughDate,
        public int $monthlyPriceInHaler,
    ) {
    }

    public static function fromOrder(Order $order): self
    {
        $situation = CustomerBillingSituation::fromOrder($order);
        $placeName = $order->storage->getPlace()->name;

        return match ($situation) {
            CustomerBillingSituation::GOPAY_FIRST_CHARGE => new self(
                situation: $situation,
                subject: 'Podepište smlouvu a zaplaťte — pronájem skladu v '.$placeName,
                headline: 'Podpis smlouvy a platba',
                nextStepLine: 'Po podpisu budete přesměrováni na platební bránu GoPay (karta + 3D Secure). Po úspěšné platbě je pronájem aktivní.',
                buttonLabel: 'Podepsat a zaplatit',
                paidThroughDate: null,
                monthlyPriceInHaler: $order->firstPaymentPrice,
            ),
            CustomerBillingSituation::EXTERNALLY_PREPAID => new self(
                situation: $situation,
                subject: 'Podepište smlouvu — předplaceno do '.($order->paidThroughDate?->format('d.m.Y') ?? ''),
                headline: 'Podpis smlouvy',
                nextStepLine: sprintf(
                    'Pronájem je předplacen externě do %s — po podpisu nemusíte nic platit.',
                    $order->paidThroughDate?->format('d.m.Y') ?? '',
                ),
                buttonLabel: 'Podepsat smlouvu',
                paidThroughDate: $order->paidThroughDate,
                monthlyPriceInHaler: 0,
            ),
            CustomerBillingSituation::FREE => new self(
                situation: $situation,
                subject: 'Podepište smlouvu — bezplatný pronájem',
                headline: 'Podpis smlouvy',
                nextStepLine: 'Bezplatný pronájem — po podpisu nemusíte nic platit.',
                buttonLabel: 'Podepsat smlouvu',
                paidThroughDate: null,
                monthlyPriceInHaler: 0,
            ),
        };
    }
}
