<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class UpdateStorageCommand
{
    /**
     * @param array{x: int|float, y: int|float, width: int|float, height: int|float, rotation: int|float, normalized?: bool} $coordinates
     */
    public function __construct(
        public Uuid $storageId,
        public string $number,
        public array $coordinates,
        public ?Uuid $storageTypeId = null,
        public ?int $pricePerWeek = null,
        public ?int $pricePerMonth = null,
        public bool $updatePrices = false,
        public ?string $commissionRate = null,
        public bool $updateCommissionRate = false,
    ) {
    }
}
