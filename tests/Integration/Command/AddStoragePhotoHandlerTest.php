<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\AddStoragePhotoCommand;
use App\Command\AddStoragePhotoHandler;
use App\Entity\Storage;
use App\Entity\StoragePhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AddStoragePhotoHandlerTest extends KernelTestCase
{
    private AddStoragePhotoHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(AddStoragePhotoHandler::class);
        /** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testAddsPhotoToStorage(): void
    {
        $storage = $this->findStorageByNumber('A1');

        $tempFile = tempnam(sys_get_temp_dir(), 'test_photo_');
        // Create a minimal valid JPEG
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $tempFile);
        unset($img);

        $file = new UploadedFile($tempFile, 'test-photo.jpg', 'image/jpeg', null, true);

        $command = new AddStoragePhotoCommand(
            storageId: $storage->id,
            file: $file,
        );

        $photo = ($this->handler)($command);

        $this->assertInstanceOf(StoragePhoto::class, $photo);
        $this->assertSame($storage->id, $photo->storage->id);
        $this->assertStringStartsWith('storages/', $photo->path);
        $this->assertStringContainsString('/photos/', $photo->path);
        $this->assertSame(1, $photo->position);

        // Clean up the uploaded file
        $uploadsDir = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/';
        $fullPath = $uploadsDir.$photo->path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testSecondPhotoGetsNextPosition(): void
    {
        $storage = $this->findStorageByNumber('A2');

        // Create first photo manually
        $firstPhoto = new StoragePhoto(
            id: \Symfony\Component\Uid\Uuid::v7(),
            storage: $storage,
            path: 'storages/test/photos/first.jpg',
            position: 1,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
        $this->entityManager->persist($firstPhoto);
        $this->entityManager->flush();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_photo_');
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $tempFile);
        unset($img);

        $file = new UploadedFile($tempFile, 'second-photo.jpg', 'image/jpeg', null, true);

        $command = new AddStoragePhotoCommand(
            storageId: $storage->id,
            file: $file,
        );

        $photo = ($this->handler)($command);

        $this->assertSame(2, $photo->position);

        // Clean up
        $uploadsDir = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/';
        $fullPath = $uploadsDir.$photo->path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    private function findStorageByNumber(string $number): Storage
    {
        $storage = $this->entityManager->getRepository(Storage::class)->findOneBy(['number' => $number]);
        \assert($storage instanceof Storage, sprintf('Storage with number "%s" not found in fixtures', $number));

        return $storage;
    }
}
