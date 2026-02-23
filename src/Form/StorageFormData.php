<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class StorageFormData
{
    #[Assert\NotBlank(message: 'Zadejte čislo skladu')]
    #[Assert\Length(max: 20, maxMessage: 'Cislo skladu nemuze byt delsi nez {{ limit }} znaku')]
    public string $number = '';

    #[Assert\NotBlank(message: 'Vyberte typ skladu')]
    public ?string $storageTypeId = null;

    #[Assert\NotBlank(message: 'Vyberte misto')]
    public ?string $placeId = null;

    #[Assert\Range(min: 0, minMessage: 'Pozice X musi byt kladna')]
    public int $coordinateX = 0;

    #[Assert\Range(min: 0, minMessage: 'Pozice Y musi byt kladna')]
    public int $coordinateY = 0;

    #[Assert\Range(min: 1, minMessage: 'Sirka musi byt alespon 1')]
    public int $coordinateWidth = 50;

    #[Assert\Range(min: 1, minMessage: 'Vyska musi byt alespon 1')]
    public int $coordinateHeight = 50;

    #[Assert\Range(min: 0, max: 360, notInRangeMessage: 'Rotace musi byt mezi 0 a 360')]
    public int $coordinateRotation = 0;

    /** Custom price in CZK (optional, null means use default from storage type) */
    #[Assert\PositiveOrZero(message: 'Cena za tyden musi byt nula nebo kladna')]
    public ?float $pricePerWeek = null;

    /** Custom price in CZK (optional, null means use default from storage type) */
    #[Assert\PositiveOrZero(message: 'Cena za mesic musi byt nula nebo kladna')]
    public ?float $pricePerMonth = null;

    /** Commission rate as percentage (0-100), e.g., 90 for 90% */
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Provize musi byt mezi 0 a 100%')]
    public ?float $commissionRate = null;

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

    public static function fromStorage(Storage $storage): self
    {
        $formData = new self();
        $formData->number = $storage->number;
        $formData->storageTypeId = $storage->storageType->id->toRfc4122();
        $formData->placeId = $storage->place->id->toRfc4122();
        $formData->coordinateX = (int) $storage->coordinates['x'];
        $formData->coordinateY = (int) $storage->coordinates['y'];
        $formData->coordinateWidth = (int) $storage->coordinates['width'];
        $formData->coordinateHeight = (int) $storage->coordinates['height'];
        $formData->coordinateRotation = (int) $storage->coordinates['rotation'];
        $formData->pricePerWeek = null !== $storage->pricePerWeek ? $storage->pricePerWeek / 100 : null;
        $formData->pricePerMonth = null !== $storage->pricePerMonth ? $storage->pricePerMonth / 100 : null;
        // Cast through float to ensure numeric-string for bcmul
        $formData->commissionRate = null !== $storage->commissionRate
            ? (float) bcmul((string) (float) $storage->commissionRate, '100', 0)
            : null;

        return $formData;
    }

    /**
     * @return array{x: int, y: int, width: int, height: int, rotation: int}
     */
    public function getCoordinates(): array
    {
        return [
            'x' => $this->coordinateX,
            'y' => $this->coordinateY,
            'width' => $this->coordinateWidth,
            'height' => $this->coordinateHeight,
            'rotation' => $this->coordinateRotation,
        ];
    }
}
