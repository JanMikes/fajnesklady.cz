<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Order;
use App\Enum\AllocationStepType;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\AuditLogRepository;
use App\Repository\BankTransactionAllocationRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\FineRepository;
use App\Repository\HandoverProtocolRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Fine\FinePaymentUrlGenerator;
use App\Service\Handover\HandoverUrlGenerator;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Payment\QrPaymentGenerator;
use App\Service\RecurringPaymentCancelUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class OrderStatusViewModelFactory
{
    public function __construct(
        private ContractRepository $contractRepository,
        private InvoiceRepository $invoiceRepository,
        private AuditLogRepository $auditLogRepository,
        private BankTransactionRepository $bankTransactionRepository,
        private FineRepository $fineRepository,
        private OrderDisplayStatusResolver $statusResolver,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator,
        private FinePaymentUrlGenerator $finePaymentUrlGenerator,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private HandoverProtocolRepository $handoverProtocolRepository,
        private HandoverUrlGenerator $handoverUrlGenerator,
        private QrPaymentGenerator $qrPaymentGenerator,
        private BankTransactionAllocationRepository $allocationRepository,
        private RecurringAmountCalculator $amountCalculator,
    ) {
    }

    public function build(Order $order): OrderStatusViewModel
    {
        $now = $this->clock->now();
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $contract = $this->contractRepository->findByOrder($order);
        $invoices = $this->invoiceRepository->findAllByOrder($order);

        $status = $this->statusResolver->resolve($order, $contract);
        $isRecurring = $order->isRecurring();

        $outstandingDebtCzk = null;
        if (null !== $contract && $contract->isTerminated() && $contract->hasOutstandingDebt()) {
            $debtAmount = $contract->outstandingDebtAmount;
            if (null !== $debtAmount) {
                $outstandingDebtCzk = (int) round($debtAmount / 100);
            }
        }

        $payNowUrl = null;
        if (in_array($order->status, [OrderStatus::CREATED, OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT], true)) {
            $payNowUrl = $this->urlGenerator->generate(
                'public_order_payment',
                ['id' => $order->id->toRfc4122()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        $cancelRecurringUrl = null;
        if (null !== $contract && $contract->hasActiveRecurringPayment()) {
            $cancelRecurringUrl = $this->cancelUrlGenerator->generate($contract);
        }

        $isCompleted = OrderStatus::COMPLETED === $order->status;

        $contractViewUrl = null;
        $contractDownloadUrl = null;
        if ($isCompleted && null !== $contract && $contract->hasDocument()) {
            $contractViewUrl = $this->statusUrlGenerator->generateContractDownload($order);
            $contractDownloadUrl = $this->statusUrlGenerator->generateContractDownload($order, forDownload: true);
        }

        // VOP is per-order from the moment the order is placed; only cancelled
        // orders hide it. Other states (created, reserved, paid, completed,
        // expired) all see the link.
        $vopViewUrl = null;
        $vopDownloadUrl = null;
        if (OrderStatus::CANCELLED !== $order->status) {
            $vopViewUrl = $this->statusUrlGenerator->generateVopDownload($order);
            $vopDownloadUrl = $this->statusUrlGenerator->generateVopDownload($order, forDownload: true);
        }

        $mapEmbedUrl = null;
        $mapDownloadUrl = null;
        if ($isCompleted && null !== $place->mapImagePath) {
            $mapEmbedUrl = $this->statusUrlGenerator->generateMapDownload($order, forDownload: false);
            $mapDownloadUrl = $this->statusUrlGenerator->generateMapDownload($order, forDownload: true);
        }

        $invoiceDownloads = [];
        if ($isCompleted) {
            foreach ($invoices as $invoice) {
                $invoiceDownloads[] = [
                    'name' => 'Faktura č. '.$invoice->invoiceNumber.(null !== $invoice->fine ? ' (smluvní pokuta)' : ''),
                    'viewUrl' => $invoice->hasPdf()
                        ? $this->statusUrlGenerator->generateInvoiceDownload($order, $invoice)
                        : '',
                    'downloadUrl' => $invoice->hasPdf()
                        ? $this->statusUrlGenerator->generateInvoiceDownload($order, $invoice, forDownload: true)
                        : '',
                    'amountCzk' => $invoice->getAmountInCzk(),
                    'hasPdf' => $invoice->hasPdf(),
                ];
            }
        }

        $newOrderUrl = null;
        if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::EXPIRED], true)) {
            $newOrderUrl = $this->urlGenerator->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        // Spec 077: an active contract can be prolonged in place — surface the
        // signed CTA so passwordless customers can act straight from /stav.
        $prolongUrl = null;
        if (null !== $contract
            && OrderStatus::COMPLETED === $order->status
            && !$contract->isTerminated()
            && !$contract->hasPendingTermination()
            && $contract->endDate >= $now->setTime(0, 0)) {
            $prolongUrl = $this->statusUrlGenerator->generateProlongation($contract);
        }

        $manualNowVariableSymbol = null;
        $manualNowBankAccount = null;
        $manualNowAmountInHaler = null;
        $manualNowPeriodStart = null;
        $manualNowQrCodeDataUri = null;
        $nextManualPaymentRequestDate = null;
        if (null !== $contract && $contract->usesManualBillingTrack()) {
            $pendingRequest = $this->manualPaymentRequestRepository->findPendingForCurrentCycle($contract, $now);
            // Spec 076: manual cycles are paid by bank transfer — surface VS + QR.
            // Spec 091 D3: ask for the cycle amount less any credit the contract
            // is holding. A credit that covers the whole cycle leaves nothing to
            // pay, so no payment instruction is rendered at all.
            $amountToRequest = null !== $pendingRequest
                ? $this->amountCalculator->amountToRequest($contract, $now)
                : 0;

            if (null !== $pendingRequest && null !== $order->variableSymbol && $amountToRequest > 0) {
                $manualNowVariableSymbol = $order->variableSymbol;
                $manualNowBankAccount = $this->qrPaymentGenerator->getBankAccountFormatted();
                $manualNowAmountInHaler = $amountToRequest;
                $manualNowPeriodStart = $pendingRequest->periodStart;
                // Embed the QR inline like every other on-page QR — the signed
                // /qr-platba route exists only for e-mails (data URIs are stripped
                // by many mail clients); an unsigned page link would 403.
                $manualNowQrCodeDataUri = $this->qrPaymentGenerator->generateDataUri($order->variableSymbol, $amountToRequest);
            }

            if (null === $pendingRequest && null !== $contract->nextBillingDate) {
                $schedule = ManualBillingReminderSchedule::fromOrder($contract->order);
                $nextManualPaymentRequestDate = $contract->nextBillingDate
                    ->setTime(0, 0, 0)
                    ->modify(sprintf('%d days', $schedule->offsetInitial));
            }
        }

        $debtPaymentUrl = null;
        if ($order->hasUnpaidDebt()) {
            $debtPaymentUrl = $this->urlGenerator->generate(
                'public_order_debt_payment',
                ['id' => $order->id->toRfc4122()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        $unpaidFines = [];
        $paidFines = [];
        if (null !== $contract) {
            $allFines = $this->fineRepository->findByContract($contract);
            foreach ($allFines as $fine) {
                if ($fine->isCancelled()) {
                    continue;
                }
                if ($fine->isPaid()) {
                    $paidFines[] = $fine;
                } else {
                    $unpaidFines[] = $fine;
                }
            }
        }

        $finePaymentUrls = [];
        foreach ($unpaidFines as $fine) {
            $finePaymentUrls[$fine->id->toRfc4122()] = $this->finePaymentUrlGenerator->generatePaymentUrl($fine);
        }

        // Spec 081: paid fines with an issued invoice get a signed download link.
        $fineInvoiceUrls = [];
        $fineInvoices = $this->invoiceRepository->findByFines($paidFines);
        foreach ($paidFines as $fine) {
            $invoice = $fineInvoices[$fine->id->toRfc4122()] ?? null;
            if (null !== $invoice && $invoice->hasPdf()) {
                $fineInvoiceUrls[$fine->id->toRfc4122()] = $this->statusUrlGenerator->generateInvoiceDownload($order, $invoice, forDownload: true);
            }
        }

        $timeline = $this->buildTimeline($order, $contract);

        // Add fine timeline entries
        foreach (array_merge($unpaidFines, $paidFines) as $fine) {
            $timeline[] = [
                'occurredAt' => $fine->issuedAt,
                'label' => sprintf('Vystavena smluvní pokuta: %s (%s Kč)', $fine->type->label(), number_format($fine->getAmountInCzk(), 0, ',', ' ')),
                'icon' => '⚠️',
            ];
            if ($fine->isPaid() && null !== $fine->paidAt) {
                $timeline[] = [
                    'occurredAt' => $fine->paidAt,
                    'label' => sprintf('Smluvní pokuta zaplacena: %s (%s Kč)', $fine->type->label(), number_format($fine->getAmountInCzk(), 0, ',', ' ')),
                    'icon' => '✅',
                ];
            }
        }

        // Re-sort timeline by date
        usort($timeline, static fn (array $a, array $b) => $a['occurredAt'] <=> $b['occurredAt']);

        $handoverProtocol = null;
        $handoverViewUrl = null;
        if (null !== $contract) {
            $handoverProtocol = $this->handoverProtocolRepository->findByContract($contract);
            if (null !== $handoverProtocol) {
                // Same trust gradient as the other signed-download links on this page:
                // the caller already passed UriSigner::checkRequest on /stav so it is
                // safe to mint a fresh tenant signature for the embedded CTA.
                $handoverViewUrl = $this->handoverUrlGenerator->generateTenantView($handoverProtocol);
            }
        }

        $isBankTransfer = PaymentMethod::BANK_TRANSFER === $order->paymentMethod;
        $bankAccount = $isBankTransfer ? $this->qrPaymentGenerator->getBankAccountFormatted() : null;
        $amountMismatchTransactions = $this->bankTransactionRepository->findAmountMismatchByOrder($order);

        // Scoped to FIRST_PAYMENT allocations (spec 091 D2).
        $partiallyPaid = $this->allocationRepository->sumForOrderByType($order, AllocationStepType::FIRST_PAYMENT);
        $remainingAmount = max(0, $order->firstPaymentPrice - $partiallyPaid);
        $effectivePaymentAmount = $remainingAmount > 0 ? $remainingAmount : $order->firstPaymentPrice;

        $qrCodeDataUri = $isBankTransfer && null !== $order->variableSymbol
            ? $this->qrPaymentGenerator->generateDataUri($order->variableSymbol, $effectivePaymentAmount)
            : null;

        return new OrderStatusViewModel(
            order: $order,
            contract: $contract,
            storage: $storage,
            storageType: $storageType,
            place: $place,
            status: $status,
            invoices: $invoices,
            isRecurring: $isRecurring,
            outstandingDebtCzk: $outstandingDebtCzk,
            timeline: $timeline,
            payNowUrl: $payNowUrl,
            cancelRecurringUrl: $cancelRecurringUrl,
            contractViewUrl: $contractViewUrl,
            contractDownloadUrl: $contractDownloadUrl,
            vopViewUrl: $vopViewUrl,
            vopDownloadUrl: $vopDownloadUrl,
            mapEmbedUrl: $mapEmbedUrl,
            mapDownloadUrl: $mapDownloadUrl,
            invoiceDownloads: $invoiceDownloads,
            newOrderUrl: $newOrderUrl,
            now: $now,
            prolongUrl: $prolongUrl,
            manualNowVariableSymbol: $manualNowVariableSymbol,
            manualNowBankAccount: $manualNowBankAccount,
            nextManualPaymentRequestDate: $nextManualPaymentRequestDate,
            manualNowAmountInHaler: $manualNowAmountInHaler,
            manualNowPeriodStart: $manualNowPeriodStart,
            manualNowQrCodeDataUri: $manualNowQrCodeDataUri,
            handoverProtocol: $handoverProtocol,
            handoverViewUrl: $handoverViewUrl,
            debtPaymentUrl: $debtPaymentUrl,
            unpaidFines: $unpaidFines,
            paidFines: $paidFines,
            finePaymentUrls: $finePaymentUrls,
            fineInvoiceUrls: $fineInvoiceUrls,
            bankAccount: $bankAccount,
            qrCodeDataUri: $qrCodeDataUri,
            remainingPaymentAmount: $partiallyPaid > 0 ? $effectivePaymentAmount : null,
            amountMismatchTransactions: $amountMismatchTransactions,
        );
    }

    /**
     * @return array<int, array{occurredAt: \DateTimeImmutable, label: string, icon: string}>
     */
    private function buildTimeline(Order $order, ?Contract $contract): array
    {
        $logs = $this->auditLogRepository->findForOrderTimeline($order->id);

        $entries = [];
        foreach ($logs as $log) {
            $rendered = $this->renderTimelineEntry($log);
            if (null !== $rendered) {
                $entries[] = $rendered;
            }
        }

        return $entries;
    }

    /**
     * @return array{occurredAt: \DateTimeImmutable, label: string, icon: string}|null
     */
    private function renderTimelineEntry(AuditLog $log): ?array
    {
        $key = $log->entityType.'.'.$log->eventType;

        $row = match ($key) {
            'order.created' => ['Objednávka vytvořena', '📝'],
            'order.reserved' => ['Skladová jednotka rezervována', '🔒'],
            'order.signed' => ['Smlouva podepsána', '✍️'],
            'order.paid' => ['Platba přijata', '💳'],
            'order.paid_externally' => ['Platba vyřízena mimo systém', '💳'],
            'order.completed' => ['Objednávka dokončena', '✅'],
            'order.cancelled' => ['Objednávka zrušena', '🚫'],
            'order.expired' => ['Rezervace vypršela', '⏰'],
            'contract.created' => ['Smlouva vytvořena', '📄'],
            'contract.signed' => ['Smlouva podepsána', '✍️'],
            'contract.terminated' => ['Smlouva ukončena', '🏁'],
            'contract.expiring_soon' => ['Smlouva se blíží ke konci', '⏳'],
            default => null,
        };

        if (null === $row) {
            return null;
        }

        return [
            'occurredAt' => $log->createdAt,
            'label' => $row[0],
            'icon' => $row[1],
        ];
    }
}
