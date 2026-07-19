<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use Symfony\Component\Uid\Uuid;

final readonly class AdminOnboardingCommand
{
    /**
     * @param ?PaymentMethod    $paymentMethod            null when $letCustomerChoosePayment is true — the customer picks at signing
     * @param ?PaymentFrequency $paymentFrequency         null when $letCustomerChoosePayment is true — the customer picks at signing
     * @param ?int              $individualMonthlyAmount  halere; null = standard storage rate; 0 = free; > 0 = individual price
     *                                                    whose meaning follows $paymentFrequency: per month (MONTHLY),
     *                                                    per year (YEARLY), or the whole-rental total (single-payment ONE_TIME)
     * @param ?string           $uploadedContractPath     absolute path to the uploaded contract document; null = no paper contract
     * @param ?string           $variableSymbolOverride   null = auto-generate for BANK_TRANSFER
     * @param bool              $letCustomerChoosePayment spec 088: when true the order is created "čeká na volbu platby" — the
     *                                                    customer chooses method + frequency at signing; $paymentMethod /
     *                                                    $paymentFrequency / $individualMonthlyAmount / $paidThroughDate /
     *                                                    $variableSymbolOverride are all null (standard ceník)
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
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?PaymentMethod $paymentMethod,
        public ?int $individualMonthlyAmount,
        public ?\DateTimeImmutable $paidThroughDate,
        public Uuid $createdByAdminId,
        public ?PaymentFrequency $paymentFrequency,
        public ?string $variableSymbolOverride = null,
        public ?string $uploadedContractPath = null,
        public ?int $debtInHaler = null,
        public bool $letCustomerChoosePayment = false,
    ) {
    }
}
