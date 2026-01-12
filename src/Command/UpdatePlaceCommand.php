<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdatePlaceCommand
{
    public function __construct(
        #[Assert\NotNull(message: 'Place ID is required')]
        public Uuid $placeId,
        #[Assert\NotBlank(message: 'Name is required')]
        #[Assert\Length(max: 255, maxMessage: 'Name cannot be longer than {{ limit }} characters')]
        public string $name,
        #[Assert\NotBlank(message: 'Address is required')]
        #[Assert\Length(max: 500, maxMessage: 'Address cannot be longer than {{ limit }} characters')]
        public string $address,
        public ?string $description,
    ) {
    }
}
