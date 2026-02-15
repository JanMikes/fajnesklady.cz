<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class PlaceProposeFormData
{
    #[Assert\NotBlank(message: 'Zadejte nazev')]
    #[Assert\Length(max: 255, maxMessage: 'Nazev nemuze byt delsi nez {{ limit }} znaku')]
    public string $name = '';

    #[Assert\Length(max: 500, maxMessage: 'Adresa nemuze byt delsi nez {{ limit }} znaku')]
    public ?string $address = null;

    #[Assert\NotBlank(message: 'Zadejte mesto')]
    #[Assert\Length(max: 100, maxMessage: 'Nazev mesta nemuze byt delsi nez {{ limit }} znaku')]
    public string $city = '';

    #[Assert\NotBlank(message: 'Zadejte PSC')]
    #[Assert\Length(max: 20, maxMessage: 'PSC nemuze byt delsi nez {{ limit }} znaku')]
    public string $postalCode = '';

    public ?string $description = null;
}
