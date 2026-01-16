<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StoragePhoto;
use App\Exception\StoragePhotoNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class StoragePhotoRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(StoragePhoto $photo): void
    {
        $this->entityManager->persist($photo);
    }

    public function delete(StoragePhoto $photo): void
    {
        $this->entityManager->remove($photo);
    }

    public function get(Uuid $id): StoragePhoto
    {
        return $this->entityManager->find(StoragePhoto::class, $id)
            ?? throw StoragePhotoNotFound::withId($id);
    }

    public function find(Uuid $id): ?StoragePhoto
    {
        return $this->entityManager->find(StoragePhoto::class, $id);
    }

    public function getNextPosition(Uuid $storageId): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(MAX(p.position), 0)')
            ->from(StoragePhoto::class, 'p')
            ->where('p.storage = :storageId')
            ->setParameter('storageId', $storageId)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $result) + 1;
    }
}
