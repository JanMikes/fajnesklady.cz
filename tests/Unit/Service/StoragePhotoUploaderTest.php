<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Identity\ProvideIdentity;
use App\Service\PublicFilesystem;
use App\Service\StoragePhotoUploader;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

class StoragePhotoUploaderTest extends TestCase
{
    private function createUploader(FilesystemOperator $filesystemOperator, ProvideIdentity $identityProvider): StoragePhotoUploader
    {
        $filesystem = new PublicFilesystem($filesystemOperator);

        return new StoragePhotoUploader($filesystem, new AsciiSlugger(), $identityProvider);
    }

    public function testUploadPhotoReturnsCorrectPath(): void
    {
        $storageId = Uuid::fromString('01933333-0000-7000-8000-000000000001');
        $fileUuid = Uuid::fromString('01933333-0000-7000-8000-000000000002');

        $expectedPath = 'storages/01933333-0000-7000-8000-000000000001/photos/my-photo-01933333-0000-7000-8000-000000000002.jpg';

        $filesystemOperator = $this->createMock(FilesystemOperator::class);
        $filesystemOperator->expects($this->once())
            ->method('writeStream')
            ->with($expectedPath, $this->anything());

        $identityProvider = $this->createMock(ProvideIdentity::class);
        $identityProvider->expects($this->once())
            ->method('next')
            ->willReturn($fileUuid);

        $file = $this->createTempUploadedFile('My Photo.jpg');

        $uploader = $this->createUploader($filesystemOperator, $identityProvider);
        $path = $uploader->uploadPhoto($file, $storageId);

        $this->assertSame($expectedPath, $path);
    }

    public function testUploadPhotoSlugifiesCzechFilename(): void
    {
        $storageId = Uuid::fromString('01933333-0000-7000-8000-000000000001');
        $fileUuid = Uuid::fromString('01933333-0000-7000-8000-000000000003');

        $filesystemOperator = $this->createMock(FilesystemOperator::class);
        $filesystemOperator->expects($this->once())->method('writeStream');

        $identityProvider = $this->createMock(ProvideIdentity::class);
        $identityProvider->method('next')->willReturn($fileUuid);

        $file = $this->createTempUploadedFile('Můj sklad číslo 5.png');

        $uploader = $this->createUploader($filesystemOperator, $identityProvider);
        $path = $uploader->uploadPhoto($file, $storageId);

        // guessExtension() returns jpg since the temp file content is JPEG
        $this->assertSame(
            'storages/01933333-0000-7000-8000-000000000001/photos/muj-sklad-cislo-5-01933333-0000-7000-8000-000000000003.jpg',
            $path,
        );
    }

    public function testDeletePhotoCallsFilesystem(): void
    {
        $filesystemOperator = $this->createMock(FilesystemOperator::class);
        $filesystemOperator->expects($this->once())
            ->method('fileExists')
            ->with('storages/abc/photos/test.jpg')
            ->willReturn(true);
        $filesystemOperator->expects($this->once())
            ->method('delete')
            ->with('storages/abc/photos/test.jpg');

        $uploader = $this->createUploader($filesystemOperator, $this->createStub(ProvideIdentity::class));

        $uploader->deletePhoto('storages/abc/photos/test.jpg');
    }

    public function testDeletePhotoSkipsNonExistentFile(): void
    {
        $filesystemOperator = $this->createMock(FilesystemOperator::class);
        $filesystemOperator->expects($this->once())
            ->method('fileExists')
            ->with('storages/abc/photos/missing.jpg')
            ->willReturn(false);
        $filesystemOperator->expects($this->never())->method('delete');

        $uploader = $this->createUploader($filesystemOperator, $this->createStub(ProvideIdentity::class));

        $uploader->deletePhoto('storages/abc/photos/missing.jpg');
    }

    private function createTempUploadedFile(string $originalName): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_photo_');
        $img = imagecreatetruecolor(1, 1);
        imagejpeg($img, $tempFile);
        unset($img);

        $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg';
        $mimeType = match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return new UploadedFile($tempFile, $originalName, $mimeType, null, true);
    }
}
