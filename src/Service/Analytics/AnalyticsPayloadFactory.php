<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Order;

/**
 * Builds the dataLayer payloads, and owns the one conversion that must not be
 * got wrong: prices are stored in haléře and Google expects major units.
 * Forgetting the /100 would report every conversion at a hundred times its
 * value, which the agency would only notice via their bidding going haywire.
 *
 * Payload keys are the ones the marketing side asked for and are part of the
 * contract with their GTM configuration — renaming one silently breaks their
 * triggers, so treat them as an external API.
 */
final readonly class AnalyticsPayloadFactory
{
    public const string CURRENCY = 'CZK';

    /**
     * @return array<string, scalar|null>
     */
    public function orderCreated(Order $order): array
    {
        return [
            'order_id' => $order->id->toRfc4122(),
            'order_number' => $order->variableSymbol,
            'place_id' => $order->storage->place->id->toRfc4122(),
            'place_name' => $order->storage->place->name,
            'storage_type_id' => $order->storage->storageType->id->toRfc4122(),
            'storage_type_name' => $order->storage->storageType->name,
            'value' => $this->toMajorUnits($order->firstPaymentPrice),
            'currency' => self::CURRENCY,
            'rental_start_date' => $order->startDate->format('Y-m-d'),
            'rental_end_date' => $order->endDate?->format('Y-m-d'),
            'rental_days' => $this->rentalDays($order),
            'payment_frequency' => $order->paymentFrequency?->value,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    public function firstPaymentSuccess(Order $order, int $amountInHaler): array
    {
        return [
            'order_id' => $order->id->toRfc4122(),
            'order_number' => $order->variableSymbol,
            'transaction_id' => $order->goPayPaymentId,
            'payment_method' => $order->paymentMethod?->value,
            'value' => $this->toMajorUnits($amountInHaler),
            'currency' => self::CURRENCY,
            'place_id' => $order->storage->place->id->toRfc4122(),
            'place_name' => $order->storage->place->name,
            'storage_type_id' => $order->storage->storageType->id->toRfc4122(),
            'storage_type_name' => $order->storage->storageType->name,
        ];
    }

    /**
     * Haléře → CZK. Kept as a float with 2 decimals, which is what GA4 and Ads
     * expect for a monetary value.
     */
    private function toMajorUnits(int $amountInHaler): float
    {
        return round($amountInHaler / 100, 2);
    }

    private function rentalDays(Order $order): ?int
    {
        if (null === $order->endDate) {
            return null;
        }

        return (int) $order->startDate->diff($order->endDate)->days;
    }
}
