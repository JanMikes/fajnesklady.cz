<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class AddStorageTypePhotoCommand
{
    public function __construct(
        public Uuid $storageTypeId,
        public UploadedFile $file,
    ) {
    }
}
