<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StorageTypePhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class StorageTypePhotoRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(StorageTypePhoto $photo): void
    {
        $this->entityManager->persist($photo);
    }

    public function delete(StorageTypePhoto $photo): void
    {
        $this->entityManager->remove($photo);
    }

    public function find(Uuid $id): ?StorageTypePhoto
    {
        return $this->entityManager->find(StorageTypePhoto::class, $id);
    }

    public function getNextPosition(Uuid $storageTypeId): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(MAX(p.position), 0)')
            ->from(StorageTypePhoto::class, 'p')
            ->where('p.storageType = :storageTypeId')
            ->setParameter('storageTypeId', $storageTypeId)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $result) + 1;
    }
}
