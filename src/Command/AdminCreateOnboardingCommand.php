<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use Symfony\Component\Uid\Uuid;

final readonly class AdminCreateOnboardingCommand
{
    /**
     * @param ?int                $individualMonthlyAmount halere; null = standard storage rate; 0 = free
     * @param ?\DateTimeImmutable $paidThroughDate         null = no external prepayment
     * @param ?Uuid               $createdByAdminId        admin user id from the security token; null in fixtures
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
        public ?int $individualMonthlyAmount = null,
        public ?\DateTimeImmutable $paidThroughDate = null,
        public ?Uuid $createdByAdminId = null,
    ) {
    }
}
