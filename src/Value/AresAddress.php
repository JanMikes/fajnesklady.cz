<?php

declare(strict_types=1);

namespace App\Value;

final readonly class AresAddress
{
    public function __construct(
        public ?string $street,
        public ?int $houseNumber,
        public ?int $orientationNumber,
        public ?string $city,
        public ?string $cityDistrict,
        public ?string $postalCode,
        public ?string $textAddress,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            street: isset($data['nazevUlice']) ? (string) $data['nazevUlice'] : null,
            houseNumber: isset($data['cisloDomovni']) ? (int) $data['cisloDomovni'] : null,
            orientationNumber: isset($data['cisloOrientacni']) ? (int) $data['cisloOrientacni'] : null,
            city: isset($data['nazevObce']) ? (string) $data['nazevObce'] : null,
            cityDistrict: isset($data['nazevMestskeCastiObvodu']) ? (string) $data['nazevMestskeCastiObvodu'] : null,
            postalCode: isset($data['psc']) ? (string) $data['psc'] : null,
            textAddress: isset($data['textovaAdresa']) ? (string) $data['textovaAdresa'] : null,
        );
    }

    public function formatStreet(): string
    {
        $streetName = $this->street ?? $this->city ?? '';

        $number = null !== $this->houseNumber ? (string) $this->houseNumber : '';
        if (null !== $this->orientationNumber) {
            $number .= '/'.$this->orientationNumber;
        }

        return trim($streetName.' '.$number);
    }

    public function formatCity(): string
    {
        return $this->city ?? $this->cityDistrict ?? '';
    }

    public function formatPostalCode(): string
    {
        return $this->postalCode ?? '';
    }
}
