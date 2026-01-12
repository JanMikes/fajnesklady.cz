<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeleteStorageTypeCommand
{
    public function __construct(
        #[Assert\NotNull(message: 'StorageType ID is required')]
        public Uuid $storageTypeId,
    ) {
    }
}
