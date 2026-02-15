<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Entity\Order;
use App\Service\GoPay\GoPayClient;
use App\Service\GoPay\GoPayException;
use App\Value\GoPayPayment;
use App\Value\GoPayPaymentStatus;

final class MockGoPayClient implements GoPayClient
{
    private int $nextPaymentId = 10000;

    /** @var array<string, GoPayPaymentStatus> */
    private array $paymentStatuses = [];

    /** @var array<string, GoPayPayment> */
    private array $createdPayments = [];

    /** @var array<string, true> */
    private array $voidedRecurrences = [];

    private bool $shouldFailNextPayment = false;

    private bool $shouldFailNextRecurrence = false;

    public function createPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment
    {
        return $this->doCreatePayment();
    }

    public function createRecurringPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment
    {
        return $this->doCreatePayment();
    }

    public function createRecurrence(string $parentPaymentId, int $amount, string $orderNumber, string $description): GoPayPayment
    {
        if ($this->shouldFailNextRecurrence) {
            $this->shouldFailNextRecurrence = false;

            throw new GoPayException('Simulated recurrence failure', 500);
        }

        $paymentId = 'gp_' . $this->nextPaymentId++;
        $payment = new GoPayPayment(
            id: $paymentId,
            gwUrl: '',
            state: 'PAID',
        );

        $this->createdPayments[$paymentId] = $payment;
        $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
            id: $paymentId,
            state: 'PAID',
            parentId: $parentPaymentId,
        );

        return $payment;
    }

    public function voidRecurrence(string $paymentId): void
    {
        if (isset($this->paymentStatuses[$paymentId])) {
            $current = $this->paymentStatuses[$paymentId];
            $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
                id: $current->id,
                state: 'CANCELED',
                parentId: $current->parentId,
            );
        }

        $this->voidedRecurrences[$paymentId] = true;
    }

    public function getStatus(string $paymentId): GoPayPaymentStatus
    {
        return $this->paymentStatuses[$paymentId]
            ?? new GoPayPaymentStatus($paymentId, 'CREATED', null);
    }

    public function getEmbedJsUrl(): string
    {
        return 'https://mock.gopay.test/embed.js';
    }

    private function doCreatePayment(): GoPayPayment
    {
        if ($this->shouldFailNextPayment) {
            $this->shouldFailNextPayment = false;

            throw new GoPayException('Simulated payment failure', 500);
        }

        $paymentId = 'gp_' . $this->nextPaymentId++;
        $payment = new GoPayPayment(
            id: $paymentId,
            gwUrl: 'https://mock.gopay.test/gw/'.$paymentId,
            state: 'CREATED',
        );

        $this->createdPayments[$paymentId] = $payment;
        $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
            id: $paymentId,
            state: 'CREATED',
            parentId: null,
        );

        return $payment;
    }

    // Test helper methods

    public function simulatePaymentPaid(string $paymentId): void
    {
        $current = $this->paymentStatuses[$paymentId] ?? null;
        $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
            id: $paymentId,
            state: 'PAID',
            parentId: $current?->parentId,
        );
    }

    public function simulatePaymentCanceled(string $paymentId): void
    {
        $current = $this->paymentStatuses[$paymentId] ?? null;
        $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
            id: $paymentId,
            state: 'CANCELED',
            parentId: $current?->parentId,
        );
    }

    public function willFailNextPayment(): void
    {
        $this->shouldFailNextPayment = true;
    }

    public function willFailNextRecurrence(): void
    {
        $this->shouldFailNextRecurrence = true;
    }

    /**
     * @return array<string, GoPayPayment>
     */
    public function getCreatedPayments(): array
    {
        return $this->createdPayments;
    }

    public function wasRecurrenceVoided(string $paymentId): bool
    {
        return isset($this->voidedRecurrences[$paymentId]);
    }

    public function reset(): void
    {
        $this->nextPaymentId = 10000;
        $this->paymentStatuses = [];
        $this->createdPayments = [];
        $this->voidedRecurrences = [];
        $this->shouldFailNextPayment = false;
        $this->shouldFailNextRecurrence = false;
    }
}
