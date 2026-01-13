<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class StorageUnavailabilityFormData
{
    #[Assert\NotBlank(message: 'Vyberte sklad')]
    public ?string $storageId = null;

    #[Assert\NotBlank(message: 'Zadejte datum zacatku')]
    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $endDate = null;

    #[Assert\NotBlank(message: 'Zadejte duvod blokovani')]
    #[Assert\Length(max: 500, maxMessage: 'Duvod nemuze byt delsi nez {{ limit }} znaku')]
    public string $reason = '';

    public bool $indefinite = false;
}
