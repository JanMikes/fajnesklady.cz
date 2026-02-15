<?php

declare(strict_types=1);

namespace App\Service\GoPay;

use App\Entity\Order;
use App\Value\GoPayPayment;
use App\Value\GoPayPaymentStatus;

interface GoPayClient
{
    /**
     * Create a standard one-time payment.
     */
    public function createPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment;

    /**
     * Create a recurring payment (ON_DEMAND) for unlimited rentals.
     * The first payment sets up recurrence, subsequent charges use createRecurrence().
     */
    public function createRecurringPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment;

    /**
     * Charge a subsequent recurring payment using parent payment ID.
     */
    public function createRecurrence(string $parentPaymentId, int $amount, string $orderNumber, string $description): GoPayPayment;

    /**
     * Cancel recurring payment capability.
     */
    public function voidRecurrence(string $paymentId): void;

    /**
     * Get payment status from GoPay.
     */
    public function getStatus(string $paymentId): GoPayPaymentStatus;

    /**
     * Get URL for embedding inline gateway JavaScript.
     */
    public function getEmbedJsUrl(): string;
}
