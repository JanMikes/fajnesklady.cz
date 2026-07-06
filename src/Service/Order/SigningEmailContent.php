<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Order;
use App\Enum\PaymentFrequency;

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
        public ?\DateTimeImmutable $billingResumesOn = null,
        public ?string $futureBillingLine = null,
        public string $cadenceLabel = 'měsíc',
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
            CustomerBillingSituation::EXTERNALLY_PREPAID => self::externallyPrepaid($order, $situation),
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

    private static function externallyPrepaid(Order $order, CustomerBillingSituation $situation): self
    {
        $paidThroughFormatted = $order->paidThroughDate?->format('d.m.Y') ?? '';
        $cadenceLabel = PaymentFrequency::YEARLY === $order->paymentFrequency ? 'rok' : 'měsíc';

        if ($order->prepaidCoversWholeTerm()) {
            return new self(
                situation: $situation,
                subject: 'Podepište smlouvu — předplaceno do '.$paidThroughFormatted,
                headline: 'Podpis smlouvy',
                nextStepLine: sprintf(
                    'Pronájem je předplacen externě do konce smlouvy (%s) — po podpisu Vás už žádné platby nečekají.',
                    $order->endDate?->format('d.m.Y') ?? $paidThroughFormatted,
                ),
                buttonLabel: 'Podepsat smlouvu',
                paidThroughDate: $order->paidThroughDate,
                monthlyPriceInHaler: $order->firstPaymentPrice,
                cadenceLabel: $cadenceLabel,
            );
        }

        return new self(
            situation: $situation,
            subject: 'Podepište smlouvu — předplaceno do '.$paidThroughFormatted,
            headline: 'Podpis smlouvy',
            nextStepLine: sprintf(
                'Pronájem je předplacen externě do %s — po podpisu nemusíte nic platit.',
                $paidThroughFormatted,
            ),
            buttonLabel: 'Podepsat smlouvu',
            paidThroughDate: $order->paidThroughDate,
            monthlyPriceInHaler: $order->firstPaymentPrice,
            billingResumesOn: $order->billingResumesOn(),
            futureBillingLine: sprintf(
                'Od %s činí nájemné %s Kč / %s (vč. DPH). Před každou splatností (%s předem) Vám pošleme e-mail s platebními údaji a QR kódem pro bankovní převod.',
                $order->billingResumesOn()?->format('d.m.Y') ?? '',
                number_format($order->firstPaymentPrice / 100, 0, ',', ' '),
                $cadenceLabel,
                self::formatDaysCzech(abs($order->manualBillingOffsetInitial)),
            ),
            cadenceLabel: $cadenceLabel,
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
