<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class AddHandoverPhotoCommand
{
    public function __construct(
        public Uuid $handoverProtocolId,
        public UploadedFile $file,
        public string $uploadedBy,
    ) {
    }
}
