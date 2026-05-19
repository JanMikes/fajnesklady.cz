<?php

declare(strict_types=1);

namespace App\Form\Address;

interface HasBillingAddress
{
    public ?string $billingStreet { get; }

    public ?string $billingCity { get; }

    public ?string $billingPostalCode { get; }

    public bool $addressOverride { get; }

    public function hasCompleteAddress(): bool;
}
