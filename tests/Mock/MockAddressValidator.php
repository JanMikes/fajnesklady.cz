<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Service\Address\AddressValidator;
use App\Value\Address\AddressSuggestion;
use App\Value\Address\AddressValidationResult;

final class MockAddressValidator implements AddressValidator
{
    private AddressValidationResult $validateResult;

    /** @var list<AddressSuggestion> */
    private array $suggestResult = [];

    public function __construct()
    {
        $this->validateResult = AddressValidationResult::skipped();
    }

    public function willValidate(AddressValidationResult $result): void
    {
        $this->validateResult = $result;
    }

    /**
     * @param list<AddressSuggestion> $suggestions
     */
    public function willReturn(array $suggestions): void
    {
        $this->suggestResult = $suggestions;
    }

    public function validate(?string $street, ?string $city, ?string $postalCode): AddressValidationResult
    {
        return $this->validateResult;
    }

    /**
     * @return list<AddressSuggestion>
     */
    public function suggest(string $query): array
    {
        return $this->suggestResult;
    }

    public function reset(): void
    {
        $this->validateResult = AddressValidationResult::skipped();
        $this->suggestResult = [];
    }
}
