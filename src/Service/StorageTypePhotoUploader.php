<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class StorageTypePhotoUploader
{
    public function __construct(
        private readonly string $uploadsDirectory,
    ) {
    }

    public function uploadPhoto(UploadedFile $file, Uuid $storageTypeId): string
    {
        $directory = $this->uploadsDirectory.'/storage-types/'.$storageTypeId->toRfc4122().'/photos';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        $filename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        $file->move($directory, $filename);

        return 'storage-types/'.$storageTypeId->toRfc4122().'/photos/'.$filename;
    }

    public function deletePhoto(string $path): void
    {
        $fullPath = $this->uploadsDirectory.'/'.$path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}
