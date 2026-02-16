<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class AddStoragePhotoCommand
{
    public function __construct(
        public Uuid $storageId,
        public UploadedFile $file,
    ) {
    }
}
