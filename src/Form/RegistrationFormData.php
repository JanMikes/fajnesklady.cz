<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Address\HasBillingAddress;
use App\Validator\AddressExists;
use Symfony\Component\Validator\Constraints as Assert;

#[AddressExists]
final class RegistrationFormData implements HasBillingAddress
{
    #[Assert\NotBlank(message: 'Zadejte prosím e-mailovou adresu.')]
    #[Assert\Email(message: 'Zadejte prosím platnou e-mailovou adresu.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Zadejte prosím jméno.')]
    #[Assert\Length(max: 100, maxMessage: 'Jméno nesmí být delší než {{ limit }} znaků.')]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Zadejte prosím příjmení.')]
    #[Assert\Length(max: 100, maxMessage: 'Příjmení nesmí být delší než {{ limit }} znaků.')]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'Zadejte telefonní číslo.')]
    #[Assert\Length(max: 20, maxMessage: 'Telefon může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^\+?[\d\s]+$/', message: 'Telefon může obsahovat pouze číslice, mezery a volitelně znak + na začátku.')]
    #[Assert\Regex(pattern: '/(?:\D*\d){9}/', message: 'Telefon musí obsahovat alespoň 9 číslic.')]
    public ?string $phone = null;

    #[Assert\NotBlank(message: 'Zadejte prosím heslo.')]
    #[Assert\Length(min: 8, minMessage: 'Heslo musí mít alespoň {{ limit }} znaků.')]
    public string $password = '';

    public bool $isCompany = false;

    #[Assert\Length(max: 255, maxMessage: 'Název firmy může mít maximálně {{ limit }} znaků.')]
    #[Assert\NotBlank(message: 'Zadejte název firmy.', groups: ['company'])]
    public ?string $companyName = null;

    #[Assert\Length(exactly: 8, exactMessage: 'IČO musí mít přesně {{ limit }} číslic.')]
    #[Assert\Regex(pattern: '/^\d{8}$/', message: 'IČO musí obsahovat pouze číslice.')]
    #[Assert\NotBlank(message: 'Zadejte IČO.', groups: ['company'])]
    public ?string $companyId = null;

    #[Assert\Length(max: 14, maxMessage: 'DIČ může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^([A-Z]{2}[A-Z0-9]{2,12})?$/', message: 'Neplatný formát DIČ. Začněte kódem země (např. CZ12345678).')]
    public ?string $companyVatId = null;

    #[Assert\Length(max: 255, maxMessage: 'Ulice může mít maximálně {{ limit }} znaků.')]
    #[Assert\NotBlank(message: 'Zadejte ulici.', groups: ['company'])]
    public ?string $billingStreet = null;

    #[Assert\Length(max: 100, maxMessage: 'Město může mít maximálně {{ limit }} znaků.')]
    #[Assert\NotBlank(message: 'Zadejte město.', groups: ['company'])]
    public ?string $billingCity = null;

    #[Assert\Length(max: 10, maxMessage: 'PSČ může mít maximálně {{ limit }} znaků.')]
    #[Assert\NotBlank(message: 'Zadejte PSČ.', groups: ['company'])]
    public ?string $billingPostalCode = null;

    public bool $addressOverride = false;

    #[Assert\IsTrue(message: 'Musíte souhlasit s obchodními podmínkami.')]
    public bool $agreeTerms = false;

    public function hasCompleteAddress(): bool
    {
        return null !== $this->billingStreet && '' !== $this->billingStreet
            && null !== $this->billingCity && '' !== $this->billingCity
            && null !== $this->billingPostalCode && '' !== $this->billingPostalCode;
    }
}
