<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DeleteStoragePhotoCommand;
use App\Command\DeleteStoragePhotoHandler;
use App\Entity\Storage;
use App\Entity\StoragePhoto;
use App\Exception\StoragePhotoNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class DeleteStoragePhotoHandlerTest extends KernelTestCase
{
    private DeleteStoragePhotoHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(DeleteStoragePhotoHandler::class);
        /** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testDeletesPhoto(): void
    {
        $storage = $this->findStorageByNumber('A1');

        $photo = new StoragePhoto(
            id: Uuid::v7(),
            storage: $storage,
            path: 'storages/test/photos/to-delete.jpg',
            position: 1,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        $photoId = $photo->id;

        $command = new DeleteStoragePhotoCommand(photoId: $photoId);
        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $deletedPhoto = $this->entityManager->find(StoragePhoto::class, $photoId);
        $this->assertNull($deletedPhoto);
    }

    public function testThrowsExceptionWhenPhotoNotFound(): void
    {
        $command = new DeleteStoragePhotoCommand(photoId: Uuid::v7());

        $this->expectException(StoragePhotoNotFound::class);

        ($this->handler)($command);
    }

    private function findStorageByNumber(string $number): Storage
    {
        $storage = $this->entityManager->getRepository(Storage::class)->findOneBy(['number' => $number]);
        \assert($storage instanceof Storage, sprintf('Storage with number "%s" not found in fixtures', $number));

        return $storage;
    }
}
