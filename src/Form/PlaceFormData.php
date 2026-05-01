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
    #[Assert\NotNull(message: 'Vyberte typ místa')]
    public PlaceType $type = PlaceType::FAJNE_SKLADY;

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

    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Nahrajte obrázek ve formátu JPEG, PNG nebo WebP',
    )]
    public ?UploadedFile $mapImage = null;

    public ?string $currentMapImagePath = null;

    #[Assert\File(
        maxSize: '10M',
        mimeTypes: ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        mimeTypesMessage: 'Nahrajte dokument ve formátu PDF nebo DOCX',
    )]
    public ?UploadedFile $operatingRulesDocument = null;

    public ?string $currentOperatingRulesPath = null;

    public bool $useMapLocation = false;

    public ?string $latitude = null;

    public ?string $longitude = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->useMapLocation) {
            if (null === $this->latitude || '' === $this->latitude) {
                $context->buildViolation('Vyberte polohu na mapě')
                    ->atPath('latitude')
                    ->addViolation();
            }
            if (null === $this->longitude || '' === $this->longitude) {
                $context->buildViolation('Vyberte polohu na mapě')
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
        $formData->currentOperatingRulesPath = $place->operatingRulesPath;
        $formData->latitude = $place->latitude;
        $formData->longitude = $place->longitude;
        $formData->useMapLocation = !$place->hasAddress() && null !== $place->latitude;

        return $formData;
    }
}
