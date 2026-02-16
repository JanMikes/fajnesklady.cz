<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Identity\ProvideIdentity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StoragePhotoUploader
{
    public function __construct(
        private PublicFilesystem $filesystem,
        private SluggerInterface $slugger,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function uploadPhoto(UploadedFile $file, Uuid $storageId): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename)->lower();
        $filename = $safeFilename.'-'.$this->identityProvider->next()->toRfc4122().'.'.$file->guessExtension();

        $path = 'storages/'.$storageId->toRfc4122().'/photos/'.$filename;

        $this->filesystem->writeUploadedFile($file, $path);

        return $path;
    }

    public function deletePhoto(string $path): void
    {
        $this->filesystem->deleteIfExists($path);
    }
}
