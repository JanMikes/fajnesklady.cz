<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class StorageUnavailabilityFormData
{
    #[Assert\NotBlank(message: 'Vyberte sklad')]
    public ?string $storageId = null;

    #[Assert\NotBlank(message: 'Zadejte datum začátku')]
    public ?\DateTimeImmutable $startDate = null;

    public ?\DateTimeImmutable $endDate = null;

    #[Assert\NotBlank(message: 'Zadejte důvod blokování')]
    #[Assert\Length(max: 500, maxMessage: 'Důvod nemůže být delší než {{ limit }} znaků')]
    public string $reason = '';

    public bool $indefinite = false;
}
