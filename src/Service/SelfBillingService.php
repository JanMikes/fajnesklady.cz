<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Exception\NoPaymentsForPeriod;
use App\Repository\LandlordInvoiceSequenceRepository;
use App\Repository\PaymentRepository;
use App\Repository\SelfBillingInvoiceRepository;
use App\Repository\UserRepository;
use App\Service\Fakturoid\FakturoidClient;
use App\Service\Identity\ProvideIdentity;

final readonly class SelfBillingService
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private SelfBillingInvoiceRepository $selfBillingInvoiceRepository,
        private LandlordInvoiceSequenceRepository $sequenceRepository,
        private UserRepository $userRepository,
        private CommissionCalculator $commissionCalculator,
        private FakturoidClient $fakturoidClient,
        private ProvideIdentity $identityProvider,
        private string $selfBillingInvoicesDirectory,
    ) {
    }

    /**
     * Get or create self-billing invoice for landlord/period (idempotent).
     *
     * @throws NoPaymentsForPeriod When no unbilled payments exist for the period
     */
    public function getOrCreateInvoice(
        User $landlord,
        int $year,
        int $month,
        \DateTimeImmutable $now,
    ): SelfBillingInvoice {
        // Check if already exists (idempotent)
        $existing = $this->selfBillingInvoiceRepository
            ->findByLandlordAndPeriod($landlord, $year, $month);

        if (null !== $existing) {
            return $existing;
        }

        // Get unbilled payments for the period
        $payments = $this->paymentRepository
            ->findUnbilledByStorageOwner($landlord, $year, $month);

        if ([] === $payments) {
            throw new NoPaymentsForPeriod($landlord, $year, $month);
        }

        // Calculate amounts with weighted commission rate
        [$grossAmount, $netAmount, $averageRate] = $this->calculateAmounts($payments);

        // Get next invoice number for landlord
        $sequence = $this->sequenceRepository
            ->getOrCreateForYear($landlord, $year);
        $invoiceNumber = $sequence->formatInvoiceNumber();
        $sequence->incrementNumber();

        // Create invoice entity
        $invoice = new SelfBillingInvoice(
            id: $this->identityProvider->next(),
            landlord: $landlord,
            year: $year,
            month: $month,
            invoiceNumber: $invoiceNumber,
            grossAmount: $grossAmount,
            commissionRate: $averageRate,
            netAmount: $netAmount,
            issuedAt: $now,
            createdAt: $now,
        );

        // Link payments to invoice
        foreach ($payments as $payment) {
            $payment->linkToSelfBillingInvoice($invoice);
        }

        // Create in Fakturoid and download PDF
        $this->createInFakturoid($invoice, $landlord, $now);

        $this->selfBillingInvoiceRepository->save($invoice);

        return $invoice;
    }

    /**
     * Calculate gross amount, net amount, and weighted average commission rate.
     *
     * @param Payment[] $payments
     *
     * @return array{int, int, string} [grossAmount, netAmount, averageRate]
     */
    private function calculateAmounts(array $payments): array
    {
        $grossAmount = 0;
        $netAmount = 0;

        foreach ($payments as $payment) {
            $rate = $this->commissionCalculator->getRate($payment->storage);
            $grossAmount += $payment->amount;
            $netAmount += $this->commissionCalculator
                ->calculateNetAmount($payment->amount, $rate);
        }

        // Calculate weighted average rate for audit purposes
        $averageRate = $grossAmount > 0
            ? (string) round($netAmount / $grossAmount, 4)
            : $this->commissionCalculator->getDefaultRate();

        return [$grossAmount, $netAmount, $averageRate];
    }

    private function createInFakturoid(
        SelfBillingInvoice $invoice,
        User $landlord,
        \DateTimeImmutable $now,
    ): void {
        $subjectId = $this->ensureFakturoidSubject($landlord, $now);

        $fakturoidInvoice = $this->fakturoidClient
            ->createSelfBillingInvoice($subjectId, $invoice);

        $invoice->setFakturoidInvoiceId($fakturoidInvoice->id);

        // Download and store PDF
        $pdfContent = $this->fakturoidClient->downloadInvoicePdf($fakturoidInvoice->id);
        $pdfPath = $this->storePdf($invoice, $pdfContent);
        $invoice->attachPdf($pdfPath);
    }

    private function ensureFakturoidSubject(User $user, \DateTimeImmutable $now): int
    {
        if ($user->hasFakturoidSubject()) {
            /** @var int $fakturoidSubjectId */
            $fakturoidSubjectId = $user->fakturoidSubjectId;

            // Sync subject data (bank account, billing details) before invoicing
            $this->fakturoidClient->updateSubject($fakturoidSubjectId, $user);

            return $fakturoidSubjectId;
        }

        $subject = $this->fakturoidClient->createSubject($user);
        $user->setFakturoidSubjectId($subject->id, $now);
        $this->userRepository->save($user);

        return $subject->id;
    }

    private function storePdf(SelfBillingInvoice $invoice, string $content): string
    {
        $filename = sprintf(
            'self_billing_%s_%s.pdf',
            $invoice->invoiceNumber,
            $invoice->createdAt->format('Ymd'),
        );

        $path = $this->selfBillingInvoicesDirectory.'/'.$filename;

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $content);

        return $path;
    }
}
