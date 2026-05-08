<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\ContractPriceChange;
use Doctrine\ORM\EntityManagerInterface;

final class ContractPriceChangeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ContractPriceChange $change): void
    {
        $this->entityManager->persist($change);
    }

    /**
     * @return ContractPriceChange[] newest first
     */
    public function findByContractOrderedByDate(Contract $contract): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('cpc')
            ->from(ContractPriceChange::class, 'cpc')
            ->where('cpc.contract = :contract')
            ->setParameter('contract', $contract)
            ->orderBy('cpc.changedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
