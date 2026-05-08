<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Contract;
use App\Entity\ContractPriceChange;
use App\Repository\ContractPriceChangeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class ContractPriceChangeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ContractPriceChangeRepository $repository;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->repository = $container->get(ContractPriceChangeRepository::class);

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;
    }

    public function testFindByContractOrderedByDateReturnsNewestFirst(): void
    {
        [$contractA, $contractB] = $this->loadTwoContracts();

        $now = $this->clock->now();
        $this->repository->save(new ContractPriceChange(
            id: Uuid::v7(),
            contract: $contractA,
            previousAmount: null,
            newAmount: 50_000,
            changedAt: $now->modify('-2 hours'),
            changedBy: null,
            reason: 'older',
        ));
        $this->repository->save(new ContractPriceChange(
            id: Uuid::v7(),
            contract: $contractA,
            previousAmount: 50_000,
            newAmount: 60_000,
            changedAt: $now,
            changedBy: null,
            reason: 'newer',
        ));
        $this->entityManager->flush();

        $rows = $this->repository->findByContractOrderedByDate($contractA);
        $this->assertCount(2, $rows);
        $this->assertSame('newer', $rows[0]->reason);
        $this->assertSame('older', $rows[1]->reason);
    }

    public function testFindByContractDoesNotLeakOtherContracts(): void
    {
        [$contractA, $contractB] = $this->loadTwoContracts();

        $now = $this->clock->now();
        $this->repository->save(new ContractPriceChange(
            id: Uuid::v7(),
            contract: $contractA,
            previousAmount: null,
            newAmount: 50_000,
            changedAt: $now,
            changedBy: null,
            reason: 'A only',
        ));
        $this->repository->save(new ContractPriceChange(
            id: Uuid::v7(),
            contract: $contractB,
            previousAmount: null,
            newAmount: 70_000,
            changedAt: $now,
            changedBy: null,
            reason: 'B only',
        ));
        $this->entityManager->flush();

        $rowsA = $this->repository->findByContractOrderedByDate($contractA);
        $this->assertCount(1, $rowsA);
        $this->assertSame('A only', $rowsA[0]->reason);

        $rowsB = $this->repository->findByContractOrderedByDate($contractB);
        $this->assertCount(1, $rowsB);
        $this->assertSame('B only', $rowsB[0]->reason);
    }

    /**
     * @return array{0: Contract, 1: Contract}
     */
    private function loadTwoContracts(): array
    {
        $contracts = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        $this->assertCount(2, $contracts);

        // Drop pre-existing price change rows from fixtures so each test
        // starts from a clean slate per contract.
        $this->entityManager->createQueryBuilder()
            ->delete(ContractPriceChange::class, 'cpc')
            ->getQuery()
            ->execute();

        return [$contracts[0], $contracts[1]];
    }
}
