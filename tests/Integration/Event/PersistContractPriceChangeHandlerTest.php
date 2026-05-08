<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\DataFixtures\ContractFixtures;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\ContractPriceChange;
use App\Entity\User;
use App\Repository\ContractPriceChangeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class PersistContractPriceChangeHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ContractPriceChangeRepository $repository;
    private ClockInterface $clock;
    private MessageBusInterface $eventBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->repository = $container->get(ContractPriceChangeRepository::class);

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;

        /** @var MessageBusInterface $eventBus */
        $eventBus = $container->get('test.event.bus');
        $this->eventBus = $eventBus;
    }

    public function testApplyingPriceRecordsHistoryRow(): void
    {
        $contract = $this->getActiveContract();
        $admin = $this->getAdmin();
        $now = $this->clock->now();

        $contract->applyIndividualMonthlyAmount(50_000, $admin, 'Test', $now);
        $events = $contract->popEvents();
        $this->assertCount(1, $events);

        $this->eventBus->dispatch($events[0]);

        $rows = $this->repository->findByContractOrderedByDate($contract);
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertInstanceOf(ContractPriceChange::class, $row);
        $this->assertNull($row->previousAmount);
        $this->assertSame(50_000, $row->newAmount);
        $this->assertSame($admin->id->toRfc4122(), $row->changedBy?->id->toRfc4122());
        $this->assertSame('Test', $row->reason);
    }

    public function testTwoChangesAreReturnedNewestFirst(): void
    {
        $contract = $this->getActiveContract();
        $now = $this->clock->now();

        $contract->applyIndividualMonthlyAmount(50_000, null, 'first', $now);
        $events = $contract->popEvents();
        $this->eventBus->dispatch($events[0]);

        $contract->applyIndividualMonthlyAmount(70_000, null, 'second', $now->modify('+1 hour'));
        $events = $contract->popEvents();
        $this->eventBus->dispatch($events[0]);

        $rows = $this->repository->findByContractOrderedByDate($contract);
        $this->assertCount(2, $rows);
        $this->assertSame('second', $rows[0]->reason);
        $this->assertSame(50_000, $rows[0]->previousAmount);
        $this->assertSame(70_000, $rows[0]->newAmount);
        $this->assertSame('first', $rows[1]->reason);
        $this->assertSame(50_000, $rows[1]->newAmount);
    }

    private function getActiveContract(): Contract
    {
        return $this->getReferenceContract(ContractFixtures::REF_CONTRACT_ACTIVE);
    }

    private function getReferenceContract(string $reference): Contract
    {
        $contracts = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->getQuery()
            ->getResult();

        foreach ($contracts as $contract) {
            if ($contract instanceof Contract) {
                return $contract;
            }
        }

        throw new \RuntimeException(sprintf('No contract found for reference %s.', $reference));
    }

    private function getAdmin(): User
    {
        $admin = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', UserFixtures::ADMIN_EMAIL)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($admin instanceof User);

        return $admin;
    }
}
