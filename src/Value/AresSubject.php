<?php

declare(strict_types=1);

namespace App\Value;

final readonly class AresSubject
{
    public function __construct(
        public string $companyId,
        public string $companyName,
        public ?string $vatId,
        public AresAddress $address,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            companyId: (string) $data['ico'],
            companyName: (string) ($data['obchodniJmeno'] ?? ''),
            vatId: isset($data['dic']) ? (string) $data['dic'] : null,
            address: AresAddress::fromArray($data['sidlo'] ?? []),
        );
    }

    public function toResult(): AresResult
    {
        return new AresResult(
            companyName: $this->companyName,
            companyId: $this->companyId,
            companyVatId: $this->vatId,
            street: $this->address->formatStreet(),
            city: $this->address->formatCity(),
            postalCode: $this->address->formatPostalCode(),
        );
    }
}
