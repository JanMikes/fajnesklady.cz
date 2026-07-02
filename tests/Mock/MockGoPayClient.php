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

    /** @var array<string, int> Amounts by GoPay paymentId — populated on each actual createRecurrence call. */
    private array $issuedRecurrenceAmounts = [];

    private bool $shouldFailNextPayment = false;

    private bool $shouldFailNextRecurrence = false;

    private bool $recurrenceReturnsCreated = false;

    private bool $recurrenceStaysPending = false;

    public function createRecurringPayment(Order $order, string $returnUrl, string $notificationUrl): GoPayPayment
    {
        return $this->doCreatePayment();
    }

    public function createRecurringCharge(
        int $amount,
        string $orderNumber,
        string $orderDescription,
        string $payerEmail,
        string $returnUrl,
        string $notificationUrl,
    ): GoPayPayment {
        $payment = $this->doCreatePayment();
        $this->paymentStatuses[$payment->id] = new GoPayPaymentStatus(
            id: $payment->id,
            state: 'CREATED',
            parentId: null,
            amount: $amount,
        );

        return $payment;
    }

    public function createOneTimeCharge(
        int $amount,
        string $orderNumber,
        string $orderDescription,
        string $payerEmail,
        string $returnUrl,
        string $notificationUrl,
    ): GoPayPayment {
        if ($this->shouldFailNextPayment) {
            $this->shouldFailNextPayment = false;

            throw new GoPayException('Simulated payment failure', 500);
        }

        $paymentId = 'gp_'.$this->nextPaymentId++;
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
            amount: $amount,
        );

        return $payment;
    }

    public function createRecurrence(string $parentPaymentId, int $amount, string $orderNumber, string $description): GoPayPayment
    {
        if ($this->shouldFailNextRecurrence) {
            $this->shouldFailNextRecurrence = false;

            throw new GoPayException('Simulated recurrence failure', 500);
        }

        $paymentId = 'gp_'.$this->nextPaymentId++;

        // Return CREATED state if configured (simulates async processing)
        // but store as PAID for getStatus (simulates GoPay confirming after a moment)
        $returnState = 'PAID';
        $statusState = 'PAID';
        if ($this->recurrenceStaysPending) {
            $this->recurrenceStaysPending = false;
            $returnState = 'CREATED';
            $statusState = 'CREATED';
        } elseif ($this->recurrenceReturnsCreated) {
            $this->recurrenceReturnsCreated = false;
            $returnState = 'CREATED';
        }

        $payment = new GoPayPayment(
            id: $paymentId,
            gwUrl: '',
            state: $returnState,
        );

        $this->createdPayments[$paymentId] = $payment;
        $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
            id: $paymentId,
            state: $statusState,
            parentId: $parentPaymentId,
            amount: $amount,
        );
        $this->issuedRecurrenceAmounts[$paymentId] = $amount;

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
                amount: $current->amount,
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

        $paymentId = 'gp_'.$this->nextPaymentId++;
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
            amount: $current?->amount,
        );
    }

    public function simulatePaymentCanceled(string $paymentId): void
    {
        $current = $this->paymentStatuses[$paymentId] ?? null;
        $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
            id: $paymentId,
            state: 'CANCELED',
            parentId: $current?->parentId,
            amount: $current?->amount,
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
     * Make the next createRecurrence return CREATED state instead of PAID.
     * getStatus will still return PAID (simulating async confirmation).
     */
    public function willReturnCreatedForRecurrence(): void
    {
        $this->recurrenceReturnsCreated = true;
    }

    /**
     * Make the next createRecurrence return CREATED AND keep getStatus
     * returning CREATED — simulates a charge that GoPay never resolves
     * (or only resolves after the polling window) so the handler must
     * record an in-flight charge.
     */
    public function willStayPendingForRecurrence(): void
    {
        $this->recurrenceStaysPending = true;
    }

    /**
     * Seed a status row directly so tests can simulate a known in-flight
     * GoPay payment without going through createRecurrence first.
     */
    public function seedRecurrenceStatus(string $paymentId, string $state, string $parentPaymentId, ?int $amount = null): void
    {
        $this->paymentStatuses[$paymentId] = new GoPayPaymentStatus(
            id: $paymentId,
            state: $state,
            parentId: $parentPaymentId,
            amount: $amount,
        );
    }

    /**
     * @return array<string, GoPayPayment>
     */
    public function getCreatedPayments(): array
    {
        return $this->createdPayments;
    }

    /**
     * @return array<string, int> Amounts charged via createRecurrence(), keyed by paymentId
     */
    public function getRecurrenceAmounts(): array
    {
        return $this->issuedRecurrenceAmounts;
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
        $this->issuedRecurrenceAmounts = [];
        $this->shouldFailNextPayment = false;
        $this->shouldFailNextRecurrence = false;
        $this->recurrenceReturnsCreated = false;
        $this->recurrenceStaysPending = false;
    }
}
