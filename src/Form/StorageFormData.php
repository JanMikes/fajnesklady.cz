<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Storage;
use Symfony\Component\Validator\Constraints as Assert;

final class StorageFormData
{
    #[Assert\NotBlank(message: 'Zadejte cislo skladu')]
    #[Assert\Length(max: 20, maxMessage: 'Cislo skladu nemuze byt delsi nez {{ limit }} znaku')]
    public string $number = '';

    #[Assert\NotBlank(message: 'Vyberte typ skladu')]
    public ?string $storageTypeId = null;

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

    public static function fromStorage(Storage $storage): self
    {
        $formData = new self();
        $formData->number = $storage->number;
        $formData->storageTypeId = $storage->storageType->id->toRfc4122();
        $formData->coordinateX = $storage->coordinates['x'];
        $formData->coordinateY = $storage->coordinates['y'];
        $formData->coordinateWidth = $storage->coordinates['width'];
        $formData->coordinateHeight = $storage->coordinates['height'];
        $formData->coordinateRotation = $storage->coordinates['rotation'];

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
