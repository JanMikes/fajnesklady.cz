<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class PlaceProposeFormData
{
    #[Assert\NotBlank(message: 'Zadejte název')]
    #[Assert\Length(max: 255, maxMessage: 'Název nemůže být delší než {{ limit }} znaků')]
    public string $name = '';

    #[Assert\Length(max: 500, maxMessage: 'Adresa nemůže být delší než {{ limit }} znaků')]
    public ?string $address = null;

    #[Assert\NotBlank(message: 'Zadejte město')]
    #[Assert\Length(max: 100, maxMessage: 'Název města nemůže být delší než {{ limit }} znaků')]
    public string $city = '';

    #[Assert\NotBlank(message: 'Zadejte PSČ')]
    #[Assert\Length(max: 20, maxMessage: 'PSČ nemůže být delší než {{ limit }} znaků')]
    public string $postalCode = '';

    public ?string $description = null;
}
