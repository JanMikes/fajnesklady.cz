<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Operations;

use App\DataFixtures\ContractFixtures;
use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Service\Operations\OperationsAlertsBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * MockClock fixed at 2025-06-15 12:00:00 UTC.
 *
 * HandoverProtocolFixtures seeds 3 baseline protocols (PENDING / TENANT_COMPLETED
 * / PENDING overdue) covering 3 contracts. Tests below use REF_CONTRACT_UNLIMITED
 * — the only active fixture contract WITHOUT a baseline handover — so they can
 * seed their own protocol without colliding with the OneToOne constraint.
 */
class OperationsAlertsBuilderTest extends KernelTestCase
{
    private OperationsAlertsBuilder $builder;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->builder = $container->get(OperationsAlertsBuilder::class);
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testPendingProtocolCountsTowardBothWaitingBuckets(): void
    {
        $now = $this->clock->now();
        $contract = $this->findContractByReference(ContractFixtures::REF_CONTRACT_UNLIMITED);

        $before = $this->builder->build($now);
        $this->seedProtocol($contract, $now->modify('-3 days'));
        $after = $this->builder->build($now);

        self::assertSame($before->handoverWaitingTenantCount + 1, $after->handoverWaitingTenantCount);
        self::assertSame($before->handoverWaitingLandlordCount + 1, $after->handoverWaitingLandlordCount);
    }

    public function testHandoverOverdueBucketFlagsProtocolsOlderThanFourteenDays(): void
    {
        $now = $this->clock->now();
        $contract = $this->findContractByReference(ContractFixtures::REF_CONTRACT_UNLIMITED);

        $before = $this->builder->build($now);
        $this->seedProtocol($contract, $now->modify('-16 days'));
        $after = $this->builder->build($now);

        self::assertSame($before->handoverOverdueCount + 1, $after->handoverOverdueCount);
    }

    public function testRecentProtocolDoesNotIncrementOverdueCount(): void
    {
        $now = $this->clock->now();
        $contract = $this->findContractByReference(ContractFixtures::REF_CONTRACT_UNLIMITED);

        $before = $this->builder->build($now);
        $this->seedProtocol($contract, $now->modify('-2 days'));
        $after = $this->builder->build($now);

        self::assertSame($before->handoverOverdueCount, $after->handoverOverdueCount);
    }

    public function testTotalPendingExcludesOverdueCount(): void
    {
        $now = $this->clock->now();
        $summary = $this->builder->build($now);

        // overdue has its own sidebar entry — including it here would double-count
        // the badge.
        $expected = count($summary->handoverViews)
            + $summary->contractsEndingWithoutProtocolCount
            + $summary->onboardingSignedUnpaidCount
            + $summary->externalPrepaymentEndingCount;

        self::assertSame($expected, $summary->totalPending);
    }

    public function testContractEndingSoonWithoutProtocolAppearsInBucket(): void
    {
        $now = $this->clock->now();
        $summary = $this->builder->build($now);

        // REF_CONTRACT_EXPIRING_7_DAYS ends in 7 days, has no handover protocol
        // (HandoverProtocolFixtures only protocols ACTIVE / TERMINATING / TERMINATED).
        $found = false;
        foreach ($summary->contractsEndingWithoutProtocol as $contract) {
            if ('D3' === $contract->storage->number) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected D3 contract (REF_CONTRACT_EXPIRING_7_DAYS) in the bucket.');
    }

    public function testContractDisappearsFromBucketOnceProtocolExists(): void
    {
        $now = $this->clock->now();
        $contract = $this->findContractByReference(ContractFixtures::REF_CONTRACT_EXPIRING_7_DAYS);

        // Sanity: D3 is in the bucket before we seed.
        $before = $this->builder->build($now);
        $beforeNumbers = array_map(static fn ($c) => $c->storage->number, $before->contractsEndingWithoutProtocol);
        self::assertContains('D3', $beforeNumbers, 'D3 should be present before seeding.');

        $this->seedProtocol($contract, $now->modify('-1 days'));

        $after = $this->builder->build($now);
        $afterNumbers = array_map(static fn ($c) => $c->storage->number, $after->contractsEndingWithoutProtocol);
        self::assertNotContains('D3', $afterNumbers, 'D3 must drop out of the bucket once a protocol exists.');
    }

    public function testTotalPendingCountMatchesScalarHelper(): void
    {
        $now = $this->clock->now();
        $contract = $this->findContractByReference(ContractFixtures::REF_CONTRACT_UNLIMITED);
        $this->seedProtocol($contract, $now->modify('-3 days'));

        $summary = $this->builder->build($now);
        $scalar = $this->builder->totalPendingCount($now);

        self::assertSame($summary->totalPending, $scalar);
    }

    private function seedProtocol(Contract $contract, \DateTimeImmutable $createdAt): HandoverProtocol
    {
        $protocol = new HandoverProtocol(
            id: Uuid::v7(),
            contract: $contract,
            createdAt: $createdAt,
        );
        $this->entityManager->persist($protocol);
        $this->entityManager->flush();

        return $protocol;
    }

    private function findContractByReference(string $reference): Contract
    {
        $number = match ($reference) {
            ContractFixtures::REF_CONTRACT_UNLIMITED => 'C1',
            ContractFixtures::REF_CONTRACT_EXPIRING_7_DAYS => 'D3',
            default => throw new \InvalidArgumentException("Unknown contract reference: $reference"),
        };

        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.number = :number')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('number', $number)
            ->getQuery()
            ->getSingleResult();
    }
}
