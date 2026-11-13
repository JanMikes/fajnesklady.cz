<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Contract;
use App\Enum\PaymentMethod;
use App\Service\OrderStatusUrlGenerator;

/**
 * Signed "set up a new card" link for bank-transfer payment e-mails — offered
 * only to tenants who originally paid by card (their card track was stopped,
 * e.g. a card change), so they can return to automatic card payments in one
 * click. Bank-transfer-native customers are not nudged.
 */
final readonly class CardSetupOffer
{
    public function __construct(
        private OrderStatusUrlGenerator $statusUrlGenerator,
    ) {
    }

    public function urlFor(Contract $contract, \DateTimeImmutable $now): ?string
    {
        if (PaymentMethod::GOPAY !== $contract->order->paidVia || !$contract->isEligibleForCardSetup($now)) {
            return null;
        }

        return $this->statusUrlGenerator->generateCardSetup($contract);
    }
}
