<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\StorageType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class StorageTypeFormData
{
    #[Assert\NotBlank(message: 'Zadejte nazev')]
    #[Assert\Length(max: 255, maxMessage: 'Nazev nemuze byt delsi nez {{ limit }} znaku')]
    public string $name = '';

    /** Inner width in centimeters */
    #[Assert\NotNull(message: 'Zadejte vnitrni sirku')]
    #[Assert\Positive(message: 'Sirka musi byt kladne cislo')]
    public ?int $innerWidth = null;

    /** Inner height in centimeters */
    #[Assert\NotNull(message: 'Zadejte vnitrni vysku')]
    #[Assert\Positive(message: 'Vyska musi byt kladne cislo')]
    public ?int $innerHeight = null;

    /** Inner length in centimeters */
    #[Assert\NotNull(message: 'Zadejte vnitrni delku')]
    #[Assert\Positive(message: 'Delka musi byt kladne cislo')]
    public ?int $innerLength = null;

    /** Outer width in centimeters (optional) */
    #[Assert\Positive(message: 'Vnejsi sirka musi byt kladne cislo')]
    public ?int $outerWidth = null;

    /** Outer height in centimeters (optional) */
    #[Assert\Positive(message: 'Vnejsi vyska musi byt kladne cislo')]
    public ?int $outerHeight = null;

    /** Outer length in centimeters (optional) */
    #[Assert\Positive(message: 'Vnejsi delka musi byt kladne cislo')]
    public ?int $outerLength = null;

    /** Price in CZK (will be converted to halire in controller) */
    #[Assert\NotNull(message: 'Zadejte cenu za tyden')]
    #[Assert\PositiveOrZero(message: 'Cena za tyden musi byt nula nebo kladna')]
    public ?float $pricePerWeek = null;

    /** Price in CZK (will be converted to halire in controller) */
    #[Assert\NotNull(message: 'Zadejte cenu za mesic')]
    #[Assert\PositiveOrZero(message: 'Cena za mesic musi byt nula nebo kladna')]
    public ?float $pricePerMonth = null;

    public ?string $description = null;

    public ?string $placeId = null;

    /**
     * @var UploadedFile[]
     */
    #[Assert\All([
        new Assert\Image(
            maxSize: '5M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'Nahrajte obrazek ve formatu JPEG, PNG nebo WebP',
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
        $formData->pricePerWeek = $storageType->getPricePerWeekInCzk();
        $formData->pricePerMonth = $storageType->getPricePerMonthInCzk();
        $formData->description = $storageType->description;
        $formData->placeId = $storageType->place->id->toRfc4122();

        return $formData;
    }
}
