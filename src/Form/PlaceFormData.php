<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Place;
use Symfony\Component\Validator\Constraints as Assert;

final class PlaceFormData
{
    #[Assert\NotBlank(message: 'Zadejte nazev')]
    #[Assert\Length(max: 255, maxMessage: 'Nazev nemuze byt delsi nez {{ limit }} znaku')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Zadejte adresu')]
    #[Assert\Length(max: 500, maxMessage: 'Adresa nemuze byt delsi nez {{ limit }} znaku')]
    public string $address = '';

    public ?string $description = null;

    public ?string $ownerId = null;

    public static function fromPlace(Place $place): self
    {
        $formData = new self();
        $formData->name = $place->name;
        $formData->address = $place->address;
        $formData->description = $place->description;
        $formData->ownerId = $place->owner->id->toRfc4122();

        return $formData;
    }
}
