<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;

final readonly class OrderStatusViewModel
{
    /**
     * @param Invoice[]                                                                                             $invoices
     * @param array<int, array{occurredAt: \DateTimeImmutable, label: string, icon: string}>                        $timeline
     * @param array<int, array{name: string, viewUrl: string, downloadUrl: string, amountCzk: float, hasPdf: bool}> $invoiceDownloads
     */
    public function __construct(
        public Order $order,
        public ?Contract $contract,
        public Storage $storage,
        public StorageType $storageType,
        public Place $place,
        public OrderDisplayStatus $status,
        public array $invoices,
        public bool $isRecurring,
        public ?int $outstandingDebtCzk,
        public array $timeline,
        public ?string $payNowUrl,
        public ?string $cancelRecurringUrl,
        public ?string $contractViewUrl,
        public ?string $contractDownloadUrl,
        public ?string $vopViewUrl,
        public ?string $vopDownloadUrl,
        public ?string $mapEmbedUrl,
        public ?string $mapDownloadUrl,
        public array $invoiceDownloads,
        public ?string $newOrderUrl,
        public \DateTimeImmutable $now,
        public ?string $payManualNowUrl = null,
        public ?\DateTimeImmutable $nextManualPaymentRequestDate = null,
        public ?int $manualNowAmountInHaler = null,
        public ?\DateTimeImmutable $manualNowPeriodStart = null,
        public ?HandoverProtocol $handoverProtocol = null,
        public ?string $handoverViewUrl = null,
        public ?string $debtPaymentUrl = null,
    ) {
    }
}
