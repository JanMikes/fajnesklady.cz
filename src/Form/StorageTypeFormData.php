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

    #[Assert\NotNull(message: 'Zadejte krátkodobou měsíční cenu')]
    #[Assert\PositiveOrZero(message: 'Krátkodobá měsíční cena musí být nula nebo kladná')]
    public ?float $defaultPricePerMonth = null;

    #[Assert\NotNull(message: 'Zadejte dlouhodobou měsíční cenu')]
    #[Assert\PositiveOrZero(message: 'Dlouhodobá měsíční cena musí být nula nebo kladná')]
    public ?float $defaultPricePerMonthLongTerm = null;

    #[Assert\NotNull(message: 'Zadejte roční cenu')]
    #[Assert\PositiveOrZero(message: 'Cena za rok musí být nula nebo kladná')]
    public ?float $defaultPricePerYear = null;

    public ?string $description = null;

    public bool $uniformStorages = true;

    /** When true, the type is hidden from all customer-facing surfaces and can only be rented out by an admin via onboarding. */
    public bool $adminOnly = false;

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
        $formData->defaultPricePerMonthLongTerm = $storageType->getDefaultPricePerMonthLongTermInCzk();
        $formData->defaultPricePerYear = $storageType->getDefaultPricePerYearInCzk();
        $formData->description = $storageType->description;
        $formData->uniformStorages = $storageType->uniformStorages;
        $formData->adminOnly = $storageType->adminOnly;

        return $formData;
    }
}
