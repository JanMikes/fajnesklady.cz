<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Place;
use App\Enum\PlaceType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PlaceFormData
{
    #[Assert\NotNull(message: 'Vyberte typ mista')]
    public PlaceType $type = PlaceType::FAJNE_SKLADY;

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

    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Nahrajte obrazek ve formatu JPEG, PNG nebo WebP',
    )]
    public ?UploadedFile $mapImage = null;

    public ?string $currentMapImagePath = null;

    public bool $useMapLocation = false;

    public ?string $latitude = null;

    public ?string $longitude = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->useMapLocation) {
            if (null === $this->latitude || '' === $this->latitude) {
                $context->buildViolation('Vyberte polohu na mape')
                    ->atPath('latitude')
                    ->addViolation();
            }
            if (null === $this->longitude || '' === $this->longitude) {
                $context->buildViolation('Vyberte polohu na mape')
                    ->atPath('longitude')
                    ->addViolation();
            }
        } else {
            if (null === $this->address || '' === $this->address) {
                $context->buildViolation('Zadejte adresu')
                    ->atPath('address')
                    ->addViolation();
            }
        }
    }

    public static function fromPlace(Place $place): self
    {
        $formData = new self();
        $formData->type = $place->type;
        $formData->name = $place->name;
        $formData->address = $place->address;
        $formData->city = $place->city;
        $formData->postalCode = $place->postalCode;
        $formData->description = $place->description;
        $formData->currentMapImagePath = $place->mapImagePath;
        $formData->latitude = $place->latitude;
        $formData->longitude = $place->longitude;
        $formData->useMapLocation = !$place->hasAddress() && $place->latitude !== null;

        return $formData;
    }
}
