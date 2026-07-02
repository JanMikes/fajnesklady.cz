<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\ContractProlongation;
use Doctrine\ORM\EntityManagerInterface;

final class ContractProlongationRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ContractProlongation $prolongation): void
    {
        $this->entityManager->persist($prolongation);
    }

    /**
     * @return ContractProlongation[]
     */
    public function findByContractOrderedByDate(Contract $contract): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(ContractProlongation::class, 'p')
            ->where('p.contract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('p.prolongedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
