<?php

declare(strict_types=1);

namespace App\Value\Address;

final readonly class AddressSuggestion
{
    public function __construct(
        public string $street,
        public string $houseNumber,
        public string $city,
        public string $postalCode,
        public string $displayLabel,
    ) {
    }

    /**
     * @return array{street: string, houseNumber: string, city: string, postalCode: string, displayLabel: string}
     */
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'houseNumber' => $this->houseNumber,
            'city' => $this->city,
            'postalCode' => $this->postalCode,
            'displayLabel' => $this->displayLabel,
        ];
    }
}
