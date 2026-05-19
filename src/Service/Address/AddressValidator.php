<?php

declare(strict_types=1);

namespace App\Service\Address;

use App\Value\Address\AddressSuggestion;
use App\Value\Address\AddressValidationResult;

interface AddressValidator
{
    public function validate(?string $street, ?string $city, ?string $postalCode): AddressValidationResult;

    /**
     * @return list<AddressSuggestion>
     */
    public function suggest(string $query): array;
}
