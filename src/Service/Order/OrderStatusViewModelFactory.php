<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Repository\AuditLogRepository;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\OrderStatusUrlGenerator;
use App\Service\RecurringPaymentCancelUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class OrderStatusViewModelFactory
{
    public function __construct(
        private ContractRepository $contractRepository,
        private InvoiceRepository $invoiceRepository,
        private AuditLogRepository $auditLogRepository,
        private OrderDisplayStatusResolver $statusResolver,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
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
                    'name' => 'Faktura č. '.$invoice->invoiceNumber,
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

        $payManualNowUrl = null;
        $manualNowAmountInHaler = null;
        $manualNowPeriodStart = null;
        $nextManualPaymentRequestDate = null;
        if (null !== $contract && BillingMode::MANUAL_RECURRING === $contract->billingMode) {
            $pendingRequest = $this->manualPaymentRequestRepository->findPendingForCurrentCycle($contract, $now);
            if (null !== $pendingRequest && null !== $pendingRequest->goPayGatewayUrl) {
                $payManualNowUrl = $pendingRequest->goPayGatewayUrl;
                $manualNowAmountInHaler = $pendingRequest->amount;
                $manualNowPeriodStart = $pendingRequest->periodStart;
            }

            if (null === $pendingRequest && null !== $contract->nextBillingDate) {
                $schedule = ManualBillingReminderSchedule::fromOrder($contract->order);
                $nextManualPaymentRequestDate = $contract->nextBillingDate
                    ->setTime(0, 0, 0)
                    ->modify(sprintf('%d days', $schedule->offsetInitial));
            }
        }

        $timeline = $this->buildTimeline($order, $contract);

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
            payManualNowUrl: $payManualNowUrl,
            nextManualPaymentRequestDate: $nextManualPaymentRequestDate,
            manualNowAmountInHaler: $manualNowAmountInHaler,
            manualNowPeriodStart: $manualNowPeriodStart,
        );
    }

    /**
     * @return array<int, array{occurredAt: \DateTimeImmutable, label: string, icon: string}>
     */
    private function buildTimeline(Order $order, ?Contract $contract): array
    {
        $logs = $this->auditLogRepository->findForOrderTimeline(
            $order->id->toRfc4122(),
            $contract?->id->toRfc4122(),
        );

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
