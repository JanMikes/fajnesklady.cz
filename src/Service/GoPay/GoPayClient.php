<?php

declare(strict_types=1);

namespace App\Service\GoPay;

use App\Entity\Order;
use App\Value\GoPayPayment;
use App\Value\GoPayPaymentStatus;

interface GoPayClient
{
    /**
     * One-time GoPay payment primitive. Spec 076: rental payments never use
     * one-shot card charges — the remaining consumers are fines and
     * onboarding-debt payments (a different legal surface).
     */
    public function createOneTimeCharge(
        int $amount,
        string $orderNumber,
        string $orderDescription,
        string $payerEmail,
        string $returnUrl,
        string $notificationUrl,
    ): GoPayPayment;

    /**
     * Create a recurring payment (ON_DEMAND) — the only way a card pays for a
     * rental. The first payment sets up recurrence, subsequent charges use
     * createRecurrence().
     */
    public function createRecurringPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment;

    /**
     * Lower-level ON_DEMAND recurring-parent primitive without an Order. Used
     * by the prolongation bank→card switch (spec 077), which charges the next
     * cycle from a Contract and stores the resulting token.
     */
    public function createRecurringCharge(
        int $amount,
        string $orderNumber,
        string $orderDescription,
        string $payerEmail,
        string $returnUrl,
        string $notificationUrl,
    ): GoPayPayment;

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
