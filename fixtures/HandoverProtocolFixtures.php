<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Seed handover protocols so the dev / staging admin operations hub and
 * banners have realistic data to render.
 *
 * MockClock is fixed to 2025-06-15 12:00:00 UTC in tests; production loads
 * fixtures rarely (dev-reset only).
 */
final class HandoverProtocolFixtures extends Fixture implements DependentFixtureInterface
{
    public const REF_HANDOVER_PENDING = 'handover-pending';
    public const REF_HANDOVER_TENANT_COMPLETED = 'handover-tenant-completed';
    public const REF_HANDOVER_OVERDUE = 'handover-overdue';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var Contract $contractActive */
        $contractActive = $this->getReference(ContractFixtures::REF_CONTRACT_ACTIVE, Contract::class);

        /** @var Contract $contractTerminating */
        $contractTerminating = $this->getReference(ContractFixtures::REF_CONTRACT_TERMINATING, Contract::class);

        /** @var Contract $contractTerminated */
        $contractTerminated = $this->getReference(ContractFixtures::REF_CONTRACT_TERMINATED, Contract::class);

        // PENDING — created 3 days ago, neither side has touched it yet.
        $pending = new HandoverProtocol(
            id: Uuid::v7(),
            contract: $contractActive,
            createdAt: $now->modify('-3 days'),
        );
        $manager->persist($pending);
        $this->addReference(self::REF_HANDOVER_PENDING, $pending);

        // TENANT_COMPLETED — tenant filled their side yesterday, landlord still owes.
        $tenantCompleted = new HandoverProtocol(
            id: Uuid::v7(),
            contract: $contractTerminating,
            createdAt: $now->modify('-5 days'),
        );
        $tenantCompleted->completeTenantSide(
            'Sklad jsem vyklidil, vše v pořádku.',
            $now->modify('-1 day'),
        );
        // Discard the HandoverCompleted event candidate — completeTenantSide alone
        // doesn't dispatch one, but the entity buffers events from the trait. Cheap
        // belt-and-suspenders so fixture loads stay side-effect free.
        $tenantCompleted->popEvents();
        $manager->persist($tenantCompleted);
        $this->addReference(self::REF_HANDOVER_TENANT_COMPLETED, $tenantCompleted);

        // PENDING > 14 days old — exercises the red-row "po termínu" path in the
        // operations hub. Terminated contract whose tenant never closed out.
        $overdue = new HandoverProtocol(
            id: Uuid::v7(),
            contract: $contractTerminated,
            createdAt: $now->modify('-16 days'),
        );
        $manager->persist($overdue);
        $this->addReference(self::REF_HANDOVER_OVERDUE, $overdue);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            ContractFixtures::class,
        ];
    }
}
