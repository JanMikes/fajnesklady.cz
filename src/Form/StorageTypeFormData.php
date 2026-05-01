<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\StorageType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class StorageTypeFormData
{
    #[Assert\NotBlank(message: 'Zadejte název')]
    #[Assert\Length(max: 255, maxMessage: 'Název nemůže být delší než {{ limit }} znaků')]
    public string $name = '';

    /** Inner width in centimeters */
    #[Assert\NotNull(message: 'Zadejte vnitřní šířku')]
    #[Assert\Positive(message: 'Šířka musí být kladné číslo')]
    public ?int $innerWidth = null;

    /** Inner height in centimeters */
    #[Assert\NotNull(message: 'Zadejte vnitřní výšku')]
    #[Assert\Positive(message: 'Výška musí být kladné číslo')]
    public ?int $innerHeight = null;

    /** Inner length in centimeters */
    #[Assert\NotNull(message: 'Zadejte vnitřní délku')]
    #[Assert\Positive(message: 'Délka musí být kladné číslo')]
    public ?int $innerLength = null;

    /** Outer width in centimeters (optional) */
    #[Assert\Positive(message: 'Vnější šířka musí být kladné číslo')]
    public ?int $outerWidth = null;

    /** Outer height in centimeters (optional) */
    #[Assert\Positive(message: 'Vnější výška musí být kladné číslo')]
    public ?int $outerHeight = null;

    /** Outer length in centimeters (optional) */
    #[Assert\Positive(message: 'Vnější délka musí být kladné číslo')]
    public ?int $outerLength = null;

    /** Default price in CZK (will be converted to halire in controller) */
    #[Assert\NotNull(message: 'Zadejte cenu za týden')]
    #[Assert\PositiveOrZero(message: 'Cena za týden musí být nula nebo kladná')]
    public ?float $defaultPricePerWeek = null;

    /** Default price in CZK (will be converted to halire in controller) */
    #[Assert\NotNull(message: 'Zadejte cenu za měsíc')]
    #[Assert\PositiveOrZero(message: 'Cena za měsíc musí být nula nebo kladná')]
    public ?float $defaultPricePerMonth = null;

    public ?string $description = null;

    public bool $uniformStorages = true;

    /**
     * @var UploadedFile[]
     */
    #[Assert\All([
        new Assert\Image(
            maxSize: '5M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'Nahrajte obrázek ve formátu JPEG, PNG nebo WebP',
        ),
    ])]
    public array $photos = [];

    public static function fromStorageType(StorageType $storageType): self
    {
        $formData = new self();
        $formData->name = $storageType->name;
        $formData->innerWidth = $storageType->innerWidth;
        $formData->innerHeight = $storageType->innerHeight;
        $formData->innerLength = $storageType->innerLength;
        $formData->outerWidth = $storageType->outerWidth;
        $formData->outerHeight = $storageType->outerHeight;
        $formData->outerLength = $storageType->outerLength;
        $formData->defaultPricePerWeek = $storageType->getDefaultPricePerWeekInCzk();
        $formData->defaultPricePerMonth = $storageType->getDefaultPricePerMonthInCzk();
        $formData->description = $storageType->description;
        $formData->uniformStorages = $storageType->uniformStorages;

        return $formData;
    }
}
