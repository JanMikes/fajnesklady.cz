<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationFormData
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
    #[Assert\Regex(pattern: '/^(CZ\d{8,10})?$/', message: 'DIČ musí být ve formátu CZxxxxxxxx.')]
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

    #[Assert\IsTrue(message: 'Musíte souhlasit s obchodními podmínkami.')]
    public bool $agreeTerms = false;
}
