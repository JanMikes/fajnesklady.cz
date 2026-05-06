<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Contract;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\TerminationReason;

final readonly class OrderDisplayStatusResolver
{
    public function resolve(Order $order, ?Contract $contract): OrderDisplayStatus
    {
        return match ($order->status) {
            OrderStatus::CREATED,
            OrderStatus::RESERVED,
            OrderStatus::AWAITING_PAYMENT => new OrderDisplayStatus(
                case: OrderDisplayStatusCase::AWAITING_PAYMENT,
                label: 'Čeká na platbu',
                variant: 'warning',
                description: 'Vaše objednávka je rezervována a čeká na zaplacení.',
            ),
            OrderStatus::PAID => new OrderDisplayStatus(
                case: OrderDisplayStatusCase::PROCESSING,
                label: 'Zpracovává se',
                variant: 'info',
                description: 'Platba byla přijata, dokončujeme přípravu smlouvy.',
            ),
            OrderStatus::CANCELLED => new OrderDisplayStatus(
                case: OrderDisplayStatusCase::CANCELLED,
                label: 'Zrušeno',
                variant: 'neutral',
                description: 'Tato objednávka byla zrušena.',
            ),
            OrderStatus::EXPIRED => new OrderDisplayStatus(
                case: OrderDisplayStatusCase::EXPIRED,
                label: 'Expirováno',
                variant: 'neutral',
                description: 'Rezervace nebyla zaplacena včas a vypršela.',
            ),
            OrderStatus::COMPLETED => $this->resolveCompleted($contract),
        };
    }

    private function resolveCompleted(?Contract $contract): OrderDisplayStatus
    {
        if (null === $contract || !$contract->isTerminated()) {
            if (null !== $contract && $contract->hasPendingTermination()) {
                return new OrderDisplayStatus(
                    case: OrderDisplayStatusCase::ACTIVE_TERMINATION_PENDING,
                    label: 'Aktivní (výpověď podána)',
                    variant: 'info',
                    description: 'Smlouva je aktivní, byla podána výpověď.',
                );
            }

            if (null !== $contract && $contract->failedBillingAttempts > 0) {
                return new OrderDisplayStatus(
                    case: OrderDisplayStatusCase::ACTIVE_BILLING_FAILED,
                    label: 'Aktivní – platba selhala',
                    variant: 'warning',
                    description: 'Smlouva běží, ale poslední automatický pokus o platbu selhal.',
                );
            }

            return new OrderDisplayStatus(
                case: OrderDisplayStatusCase::ACTIVE,
                label: 'Aktivní',
                variant: 'success',
                description: 'Vaše skladová jednotka je k dispozici.',
            );
        }

        return match ($contract->terminationReason) {
            TerminationReason::PAYMENT_FAILURE => new OrderDisplayStatus(
                case: OrderDisplayStatusCase::SUSPENDED_PAYMENT_FAILURE,
                label: 'Pozastaveno z důvodu nezaplacení',
                variant: 'error',
                description: 'Smlouva byla ukončena pro neuhrazené platby.',
            ),
            TerminationReason::EXPIRED => new OrderDisplayStatus(
                case: OrderDisplayStatusCase::COMPLETED_ENDED,
                label: 'Dokončeno',
                variant: 'neutral',
                description: 'Smlouva řádně skončila.',
            ),
            default => new OrderDisplayStatus(
                case: OrderDisplayStatusCase::TERMINATED,
                label: 'Smlouva ukončena',
                variant: 'neutral',
                description: 'Smlouva byla ukončena.',
            ),
        };
    }
}
