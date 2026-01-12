<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\StorageType;
use Symfony\Component\Validator\Constraints as Assert;

final class StorageTypeFormData
{
    #[Assert\NotBlank(message: 'Zadejte nazev')]
    #[Assert\Length(max: 255, maxMessage: 'Nazev nemuze byt delsi nez {{ limit }} znaku')]
    public string $name = '';

    /** Width in centimeters */
    #[Assert\NotNull(message: 'Zadejte sirku')]
    #[Assert\Positive(message: 'Sirka musi byt kladne cislo')]
    public ?int $width = null;

    /** Height in centimeters */
    #[Assert\NotNull(message: 'Zadejte vysku')]
    #[Assert\Positive(message: 'Vyska musi byt kladne cislo')]
    public ?int $height = null;

    /** Length in centimeters */
    #[Assert\NotNull(message: 'Zadejte delku')]
    #[Assert\Positive(message: 'Delka musi byt kladne cislo')]
    public ?int $length = null;

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

    public static function fromStorageType(StorageType $storageType): self
    {
        $formData = new self();
        $formData->name = $storageType->name;
        $formData->width = $storageType->width;
        $formData->height = $storageType->height;
        $formData->length = $storageType->length;
        $formData->pricePerWeek = $storageType->getPricePerWeekInCzk();
        $formData->pricePerMonth = $storageType->getPricePerMonthInCzk();
        $formData->description = $storageType->description;
        $formData->placeId = $storageType->place->id->toRfc4122();

        return $formData;
    }
}
