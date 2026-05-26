<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\BillingMode;
use App\Enum\ExpectedDuration;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use Symfony\Component\Uid\Uuid;

final readonly class AdminOnboardingCommand
{
    /**
     * @param ?int    $individualMonthlyAmount halere; null = standard storage rate; 0 = free
     * @param ?string $uploadedContractPath    absolute path to the uploaded contract document; null = no paper contract
     * @param ?string $variableSymbolOverride  null = auto-generate for BANK_TRANSFER
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
        public PaymentMethod $paymentMethod,
        public ?int $individualMonthlyAmount,
        public ?\DateTimeImmutable $paidThroughDate,
        public Uuid $createdByAdminId,
        public BillingMode $billingMode,
        public ?ExpectedDuration $expectedDuration,
        public PaymentFrequency $paymentFrequency,
        public ?string $variableSymbolOverride = null,
        public ?string $uploadedContractPath = null,
    ) {
    }
}
