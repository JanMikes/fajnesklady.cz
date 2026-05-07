<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\RentalType;

final readonly class AdminMigrateCustomerCommand
{
    /**
     * @param int                 $totalPrice              Halere — lump-sum amount paid externally; recorded as the initial Payment
     * @param ?int                $individualMonthlyAmount Halere; null = standard storage rate; 0 = free
     * @param ?\DateTimeImmutable $paidThroughDate         Required for migrate; defaults to endDate (LIMITED) when omitted
     */
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public ?\DateTimeImmutable $birthDate,
        public ?string $companyName,
        public ?string $companyId,
        public ?string $companyVatId,
        public ?string $billingStreet,
        public ?string $billingCity,
        public ?string $billingPostalCode,
        public Storage $storage,
        public StorageType $storageType,
        public Place $place,
        public RentalType $rentalType,
        public \DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public string $contractDocumentPath,
        public int $totalPrice,
        public \DateTimeImmutable $paidAt,
        public ?int $individualMonthlyAmount = null,
        public ?\DateTimeImmutable $paidThroughDate = null,
    ) {
    }
}
