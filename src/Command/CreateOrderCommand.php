<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;

/**
 * Create a new order with storage assignment.
 */
final readonly class CreateOrderCommand
{
    public function __construct(
        public User $user,
        public StorageType $storageType,
        public Place $place,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY,
        public ?Storage $preSelectedStorage = null,
    ) {
    }
}
