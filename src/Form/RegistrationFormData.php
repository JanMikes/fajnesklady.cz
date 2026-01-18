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

    #[Assert\IsTrue(message: 'Musíte souhlasit s obchodními podmínkami.')]
    public bool $agreeTerms = false;
}
