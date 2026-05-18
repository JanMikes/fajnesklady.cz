<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PlaceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Place
{
    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    public private(set) ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    public private(set) ?string $longitude = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $mapImagePath = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $operatingRulesPath = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $instructionsPath = null;

    #[ORM\Column]
    public private(set) int $daysInAdvance = 0;

    #[ORM\Column]
    public private(set) int $orderExpirationDays = 3;

    #[ORM\Column(options: ['default' => false])]
    public private(set) bool $storageCodesEnabled = false;

    #[ORM\Column(options: ['default' => 4])]
    public private(set) int $storageCodeDigits = 4;

    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $storageCodeFrom = 0;

    #[ORM\Column(options: ['default' => 9999])]
    public private(set) int $storageCodeTo = 9999;

    /**
     * Days relative to {@see Contract::$nextBillingDate} on which each manual-
     * billing reminder fires. Negative = before due date, positive = after.
     * Snapshotted onto each new Order so running rentals keep their original
     * cadence when the operator edits these later.
     */
    #[ORM\Column(options: ['default' => -7])]
    public private(set) int $manualBillingOffsetInitial = -7;

    #[ORM\Column(options: ['default' => -2])]
    public private(set) int $manualBillingOffsetReminder = -2;

    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $manualBillingOffsetFinalDue = 0;

    #[ORM\Column(options: ['default' => 3])]
    public private(set) int $manualBillingOffsetOverdueFirst = 3;

    #[ORM\Column(options: ['default' => 7])]
    public private(set) int $manualBillingOffsetOverdueFinal = 7;

    #[ORM\Column]
    public private(set) bool $isActive = true;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column(length: 500, nullable: true)]
        private(set) ?string $address,
        #[ORM\Column(length: 100)]
        private(set) string $city,
        #[ORM\Column(length: 20)]
        private(set) string $postalCode,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $description,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 20)]
        private(set) PlaceType $type = PlaceType::FAJNE_SKLADY,
    ) {
        $this->updatedAt = $this->createdAt;
    }

    public function hasAddress(): bool
    {
        return null !== $this->address;
    }

    public function updateDetails(
        string $name,
        ?string $address,
        string $city,
        string $postalCode,
        ?string $description,
        PlaceType $type,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->address = $address;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->description = $description;
        $this->type = $type;
        $this->updatedAt = $now;
    }

    public function updateLocation(
        ?string $latitude,
        ?string $longitude,
        \DateTimeImmutable $now,
    ): void {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->updatedAt = $now;
    }

    public function updateMapImage(?string $mapImagePath, \DateTimeImmutable $now): void
    {
        $this->mapImagePath = $mapImagePath;
        $this->updatedAt = $now;
    }

    public function hasOperatingRules(): bool
    {
        return null !== $this->operatingRulesPath;
    }

    public function updateOperatingRules(?string $operatingRulesPath, \DateTimeImmutable $now): void
    {
        $this->operatingRulesPath = $operatingRulesPath;
        $this->updatedAt = $now;
    }

    public function hasInstructions(): bool
    {
        return null !== $this->instructionsPath;
    }

    public function updateInstructions(?string $instructionsPath, \DateTimeImmutable $now): void
    {
        $this->instructionsPath = $instructionsPath;
        $this->updatedAt = $now;
    }

    public function updateDaysInAdvance(int $daysInAdvance, \DateTimeImmutable $now): void
    {
        $this->daysInAdvance = $daysInAdvance;
        $this->updatedAt = $now;
    }

    public function updateOrderExpirationDays(int $orderExpirationDays, \DateTimeImmutable $now): void
    {
        $this->orderExpirationDays = $orderExpirationDays;
        $this->updatedAt = $now;
    }

    public function updateStorageCodeConfig(
        bool $enabled,
        int $digits,
        int $from,
        int $to,
        \DateTimeImmutable $now,
    ): void {
        $this->storageCodesEnabled = $enabled;
        $this->storageCodeDigits = $digits;
        $this->storageCodeFrom = $from;
        $this->storageCodeTo = $to;
        $this->updatedAt = $now;
    }

    public function storageCodeRangeSize(): int
    {
        return $this->storageCodeTo - $this->storageCodeFrom + 1;
    }

    public function updateManualBillingSchedule(
        int $initial,
        int $reminder,
        int $finalDue,
        int $overdueFirst,
        int $overdueFinal,
        \DateTimeImmutable $now,
    ): void {
        $this->manualBillingOffsetInitial = $initial;
        $this->manualBillingOffsetReminder = $reminder;
        $this->manualBillingOffsetFinalDue = $finalDue;
        $this->manualBillingOffsetOverdueFirst = $overdueFirst;
        $this->manualBillingOffsetOverdueFinal = $overdueFinal;
        $this->updatedAt = $now;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->isActive = true;
        $this->updatedAt = $now;
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->isActive = false;
        $this->updatedAt = $now;
    }
}
