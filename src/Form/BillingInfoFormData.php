<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Form\Address\HasBillingAddress;
use App\Validator\AddressExists;
use Symfony\Component\Validator\Constraints as Assert;

#[AddressExists]
final class BillingInfoFormData implements HasBillingAddress
{
    #[Assert\Length(max: 255, maxMessage: 'Název firmy může mít maximálně {{ limit }} znaků.')]
    public ?string $companyName = null;

    #[Assert\Length(exactly: 8, exactMessage: 'IČO musí mít přesně {{ limit }} číslic.')]
    #[Assert\Regex(pattern: '/^\d{8}$/', message: 'IČO musí obsahovat pouze číslice.')]
    public ?string $companyId = null;

    #[Assert\Length(max: 14, maxMessage: 'DIČ může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^([A-Z]{2}[A-Z0-9]{2,12})?$/', message: 'Neplatný formát DIČ. Začněte kódem země (např. CZ12345678).')]
    public ?string $companyVatId = null;

    #[Assert\Length(max: 255, maxMessage: 'Ulice může mít maximálně {{ limit }} znaků.')]
    public ?string $billingStreet = null;

    #[Assert\Length(max: 100, maxMessage: 'Město může mít maximálně {{ limit }} znaků.')]
    public ?string $billingCity = null;

    #[Assert\Length(max: 10, maxMessage: 'PSČ může mít maximálně {{ limit }} znaků.')]
    public ?string $billingPostalCode = null;

    public bool $addressOverride = false;

    public function hasCompleteAddress(): bool
    {
        return null !== $this->billingStreet && '' !== $this->billingStreet
            && null !== $this->billingCity && '' !== $this->billingCity
            && null !== $this->billingPostalCode && '' !== $this->billingPostalCode;
    }

    public static function fromUser(User $user): self
    {
        $formData = new self();
        $formData->companyName = $user->companyName;
        $formData->companyId = $user->companyId;
        $formData->companyVatId = $user->companyVatId;
        $formData->billingStreet = $user->billingStreet;
        $formData->billingCity = $user->billingCity;
        $formData->billingPostalCode = $user->billingPostalCode;

        return $formData;
    }
}
