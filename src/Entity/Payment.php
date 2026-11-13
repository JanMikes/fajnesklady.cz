<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentMethod;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(
    name: 'uniq_payment_gopay_payment_id',
    columns: ['go_pay_payment_id'],
    options: ['where' => '(go_pay_payment_id IS NOT NULL)'],
)]
class Payment
{
    #[ORM\ManyToOne(targetEntity: SelfBillingInvoice::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?SelfBillingInvoice $selfBillingInvoice = null;

    #[ORM\Column(length: 50, nullable: true)]
    public private(set) ?string $goPayPaymentId = null;

    // Set when the payment was reconciled from an incoming bank transfer —
    // mutually exclusive with goPayPaymentId, which is only ever a GoPay id.
    #[ORM\ManyToOne(targetEntity: BankTransaction::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?BankTransaction $bankTransaction = null;

    // The billing cycle this payment settled. Nullable only because rows
    // predate the columns — every new recurring payment carries its period.
    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $periodEnd = null;

    // Set on a refund ("storno") row: a NEGATIVE amount booked when GoPay
    // confirmed money went back for the referenced payment. Keeping refunds in
    // the ledger makes every SUM(amount) net revenue with no special-casing.
    // Such a row is never evidence that something was paid — lookups that mean
    // "the payment that paid X" must exclude it.
    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?Payment $refundOf = null;

    /**
     * How the money reached us, read off the stored source links: a GoPay id
     * is only ever written once GoPay confirmed the charge, a bank transaction
     * only once FIO money was paired. Every other row was recorded by an admin
     * (onboarding, off-system settlements). Zero-amount formality rows also
     * land on EXTERNAL — no money moved at all.
     */
    public PaymentMethod $paidVia {
        get => match (true) {
            null !== $this->refundOf => $this->refundOf->paidVia,
            null !== $this->goPayPaymentId => PaymentMethod::GOPAY,
            null !== $this->bankTransaction => PaymentMethod::BANK_TRANSFER,
            default => PaymentMethod::EXTERNAL,
        };
    }

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?Order $order,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?Contract $contract,
        #[ORM\ManyToOne(targetEntity: Storage::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Storage $storage,
        #[ORM\Column]
        private(set) int $amount,
        #[ORM\Column]
        private(set) \DateTimeImmutable $paidAt,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function setGoPayPaymentId(string $goPayPaymentId): void
    {
        $this->goPayPaymentId = $goPayPaymentId;
    }

    public function linkBankTransaction(BankTransaction $bankTransaction): void
    {
        $this->bankTransaction = $bankTransaction;
    }

    public function coverPeriod(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): void
    {
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
    }

    /**
     * Book this row as the storno of $original — money returned to the payer.
     */
    public function markAsRefundOf(Payment $original): void
    {
        if ($this->amount >= 0) {
            throw new \DomainException('A refund row must carry a negative amount.');
        }
        if ($original->isRefund()) {
            throw new \DomainException('Cannot refund a refund row.');
        }

        $this->refundOf = $original;
    }

    public function isRefund(): bool
    {
        return null !== $this->refundOf;
    }

    public function linkToSelfBillingInvoice(SelfBillingInvoice $invoice): void
    {
        $this->selfBillingInvoice = $invoice;
    }

    public function isBilled(): bool
    {
        return null !== $this->selfBillingInvoice;
    }

    public function getAmountInCzk(): float
    {
        return $this->amount / 100;
    }
}
