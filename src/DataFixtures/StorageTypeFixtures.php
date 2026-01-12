<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\StorageType;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class StorageTypeFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var User $landlord */
        $landlord = $this->getReference('user-landlord', User::class);

        /** @var User $admin */
        $admin = $this->getReference('user-admin', User::class);

        // Storage types for landlord
        $storageType1 = new StorageType(
            id: Uuid::v7(),
            name: 'Maly box',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 15000, // 150 CZK
            pricePerMonth: 50000, // 500 CZK
            owner: $landlord,
            createdAt: $now,
        );
        $manager->persist($storageType1);

        $storageType2 = new StorageType(
            id: Uuid::v7(),
            name: 'Stredni box',
            width: '2.0',
            height: '2.0',
            length: '2.0',
            pricePerWeek: 35000, // 350 CZK
            pricePerMonth: 120000, // 1200 CZK
            owner: $landlord,
            createdAt: $now,
        );
        $manager->persist($storageType2);

        $storageType3 = new StorageType(
            id: Uuid::v7(),
            name: 'Velky box',
            width: '3.0',
            height: '2.5',
            length: '4.0',
            pricePerWeek: 80000, // 800 CZK
            pricePerMonth: 280000, // 2800 CZK
            owner: $landlord,
            createdAt: $now,
        );
        $manager->persist($storageType3);

        // Storage type for admin (to test admin can see all)
        $storageType4 = new StorageType(
            id: Uuid::v7(),
            name: 'Premium box',
            width: '5.0',
            height: '3.0',
            length: '6.0',
            pricePerWeek: 150000, // 1500 CZK
            pricePerMonth: 500000, // 5000 CZK
            owner: $admin,
            createdAt: $now,
        );
        $manager->persist($storageType4);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
