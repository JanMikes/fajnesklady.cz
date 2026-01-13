<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class PlaceFileUploader
{
    public function __construct(
        private readonly string $uploadsDirectory,
    ) {
    }

    public function uploadMapImage(UploadedFile $file, Uuid $placeId): string
    {
        return $this->uploadFile($file, $placeId, 'maps');
    }

    public function uploadContractTemplate(UploadedFile $file, Uuid $placeId): string
    {
        return $this->uploadFile($file, $placeId, 'templates');
    }

    public function deleteFile(?string $path): void
    {
        if (null === $path) {
            return;
        }

        $fullPath = $this->uploadsDirectory.'/'.$path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    private function uploadFile(UploadedFile $file, Uuid $placeId, string $subdirectory): string
    {
        $directory = $this->uploadsDirectory.'/places/'.$placeId->toRfc4122().'/'.$subdirectory;

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        $filename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        $file->move($directory, $filename);

        return 'places/'.$placeId->toRfc4122().'/'.$subdirectory.'/'.$filename;
    }
}
