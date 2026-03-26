<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HandoverPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class HandoverPhotoRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(HandoverPhoto $photo): void
    {
        $this->entityManager->persist($photo);
    }

    public function delete(HandoverPhoto $photo): void
    {
        $this->entityManager->remove($photo);
    }

    public function get(Uuid $id): HandoverPhoto
    {
        return $this->entityManager->find(HandoverPhoto::class, $id)
            ?? throw new \RuntimeException(sprintf('Handover photo "%s" not found.', $id->toRfc4122()));
    }

    public function getNextPosition(Uuid $handoverProtocolId, string $uploadedBy): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('MAX(p.position)')
            ->from(HandoverPhoto::class, 'p')
            ->where('p.handoverProtocol = :protocolId')
            ->andWhere('p.uploadedBy = :uploadedBy')
            ->setParameter('protocolId', $handoverProtocolId)
            ->setParameter('uploadedBy', $uploadedBy)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $result ? 0 : ((int) $result) + 1;
    }
}
