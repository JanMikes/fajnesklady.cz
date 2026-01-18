<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class LandlordRegistrationFormData
{
    #[Assert\NotBlank(message: 'Zadejte prosím e-mailovou adresu')]
    #[Assert\Email(message: 'Zadejte platnou e-mailovou adresu')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Zadejte prosím heslo')]
    #[Assert\Length(min: 8, minMessage: 'Heslo musí mít alespoň {{ limit }} znaků')]
    public string $password = '';

    #[Assert\NotBlank(message: 'Zadejte prosím jméno')]
    #[Assert\Length(max: 100, maxMessage: 'Jméno může mít maximálně {{ limit }} znaků')]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Zadejte prosím příjmení')]
    #[Assert\Length(max: 100, maxMessage: 'Příjmení může mít maximálně {{ limit }} znaků')]
    public string $lastName = '';

    #[Assert\Length(max: 20, maxMessage: 'Telefon může mít maximálně {{ limit }} znaků')]
    public ?string $phone = null;

    #[Assert\NotBlank(message: 'IČO je povinné')]
    #[Assert\Regex(pattern: '/^\d{8}$/', message: 'IČO musí mít přesně 8 číslic')]
    public string $companyId = '';

    #[Assert\NotBlank(message: 'Název firmy je povinný')]
    #[Assert\Length(max: 255, maxMessage: 'Název firmy může mít maximálně {{ limit }} znaků')]
    public string $companyName = '';

    #[Assert\Regex(pattern: '/^CZ\d{8,10}$/', message: 'DIČ musí být ve formátu CZxxxxxxxx')]
    public ?string $companyVatId = null;

    #[Assert\NotBlank(message: 'Ulice je povinná')]
    #[Assert\Length(max: 255, maxMessage: 'Ulice může mít maximálně {{ limit }} znaků')]
    public string $billingStreet = '';

    #[Assert\NotBlank(message: 'Město je povinné')]
    #[Assert\Length(max: 100, maxMessage: 'Město může mít maximálně {{ limit }} znaků')]
    public string $billingCity = '';

    #[Assert\NotBlank(message: 'PSČ je povinné')]
    #[Assert\Regex(pattern: '/^\d{3}\s?\d{2}$/', message: 'PSČ musí být ve formátu XXX XX')]
    public string $billingPostalCode = '';

    #[Assert\IsTrue(message: 'Musíte souhlasit s podmínkami.')]
    public bool $agreeTerms = false;
}
