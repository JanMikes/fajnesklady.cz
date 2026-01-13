<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Place;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class PlaceFormData
{
    #[Assert\NotBlank(message: 'Zadejte nazev')]
    #[Assert\Length(max: 255, maxMessage: 'Nazev nemuze byt delsi nez {{ limit }} znaku')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Zadejte adresu')]
    #[Assert\Length(max: 500, maxMessage: 'Adresa nemuze byt delsi nez {{ limit }} znaku')]
    public string $address = '';

    #[Assert\NotBlank(message: 'Zadejte mesto')]
    #[Assert\Length(max: 100, maxMessage: 'Nazev mesta nemuze byt delsi nez {{ limit }} znaku')]
    public string $city = '';

    #[Assert\NotBlank(message: 'Zadejte PSC')]
    #[Assert\Length(max: 20, maxMessage: 'PSC nemuze byt delsi nez {{ limit }} znaku')]
    public string $postalCode = '';

    public ?string $description = null;

    public ?string $ownerId = null;

    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Nahrajte obrazek ve formatu JPEG, PNG nebo WebP',
    )]
    public ?UploadedFile $mapImage = null;

    #[Assert\File(
        maxSize: '10M',
        mimeTypes: ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        mimeTypesMessage: 'Nahrajte dokument ve formatu DOCX',
    )]
    public ?UploadedFile $contractTemplate = null;

    public ?string $currentMapImagePath = null;

    public ?string $currentContractTemplatePath = null;

    public static function fromPlace(Place $place): self
    {
        $formData = new self();
        $formData->name = $place->name;
        $formData->address = $place->address;
        $formData->city = $place->city;
        $formData->postalCode = $place->postalCode;
        $formData->description = $place->description;
        $formData->ownerId = $place->owner->id->toRfc4122();
        $formData->currentMapImagePath = $place->mapImagePath;
        $formData->currentContractTemplatePath = $place->contractTemplatePath;

        return $formData;
    }
}
