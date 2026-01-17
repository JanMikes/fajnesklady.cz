<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LandlordInvoiceSequence;
use App\Entity\User;
use App\Service\Identity\ProvideIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class LandlordInvoiceSequenceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProvideIdentity $identityProvider,
    ) {
    }

    public function save(LandlordInvoiceSequence $sequence): void
    {
        $this->entityManager->persist($sequence);
    }

    public function find(Uuid $id): ?LandlordInvoiceSequence
    {
        return $this->entityManager->find(LandlordInvoiceSequence::class, $id);
    }

    /**
     * Find sequence for a landlord and year.
     */
    public function findByLandlordAndYear(User $landlord, int $year): ?LandlordInvoiceSequence
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(LandlordInvoiceSequence::class, 's')
            ->where('s.landlord = :landlord')
            ->andWhere('s.year = :year')
            ->setParameter('landlord', $landlord)
            ->setParameter('year', $year)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get or create sequence for a landlord and year.
     */
    public function getOrCreateForYear(User $landlord, int $year): LandlordInvoiceSequence
    {
        $sequence = $this->findByLandlordAndYear($landlord, $year);

        if (null === $sequence) {
            $sequence = new LandlordInvoiceSequence(
                id: $this->identityProvider->next(),
                landlord: $landlord,
                year: $year,
            );
            $this->save($sequence);
        }

        return $sequence;
    }
}
