<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateStorageTypeCommand
{
    public function __construct(
        #[Assert\NotNull(message: 'StorageType ID is required')]
        public Uuid $storageTypeId,
        #[Assert\NotBlank(message: 'Name is required')]
        #[Assert\Length(max: 255, maxMessage: 'Name cannot be longer than {{ limit }} characters')]
        public string $name,
        #[Assert\NotBlank(message: 'Width is required')]
        #[Assert\Positive(message: 'Width must be a positive number')]
        public string $width,
        #[Assert\NotBlank(message: 'Height is required')]
        #[Assert\Positive(message: 'Height must be a positive number')]
        public string $height,
        #[Assert\NotBlank(message: 'Length is required')]
        #[Assert\Positive(message: 'Length must be a positive number')]
        public string $length,
        #[Assert\NotNull(message: 'Price per week is required')]
        #[Assert\PositiveOrZero(message: 'Price per week must be zero or positive')]
        public int $pricePerWeek,
        #[Assert\NotNull(message: 'Price per month is required')]
        #[Assert\PositiveOrZero(message: 'Price per month must be zero or positive')]
        public int $pricePerMonth,
    ) {
    }
}
