<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Place;
use App\Entity\StorageType;
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

        /** @var Place $placePrahaCentrum */
        $placePrahaCentrum = $this->getReference('place-praha-centrum', Place::class);

        /** @var Place $placePrahaJih */
        $placePrahaJih = $this->getReference('place-praha-jih', Place::class);

        /** @var Place $placeBrno */
        $placeBrno = $this->getReference('place-brno', Place::class);

        // Storage types for Praha Centrum
        $smallBox = new StorageType(
            id: Uuid::v7(),
            name: 'Maly box',
            width: 100,   // 1m in cm
            height: 100,
            length: 100,
            pricePerWeek: 15000,   // 150 CZK
            pricePerMonth: 50000,  // 500 CZK
            place: $placePrahaCentrum,
            createdAt: $now,
        );
        $manager->persist($smallBox);
        $this->addReference('storage-type-small', $smallBox);

        $mediumBox = new StorageType(
            id: Uuid::v7(),
            name: 'Stredni box',
            width: 200,   // 2m in cm
            height: 200,
            length: 200,
            pricePerWeek: 35000,   // 350 CZK
            pricePerMonth: 120000, // 1200 CZK
            place: $placePrahaCentrum,
            createdAt: $now,
        );
        $manager->persist($mediumBox);
        $this->addReference('storage-type-medium', $mediumBox);

        $largeBox = new StorageType(
            id: Uuid::v7(),
            name: 'Velky box',
            width: 300,   // 3m in cm
            height: 250,
            length: 400,
            pricePerWeek: 80000,   // 800 CZK
            pricePerMonth: 280000, // 2800 CZK
            place: $placePrahaCentrum,
            createdAt: $now,
        );
        $manager->persist($largeBox);
        $this->addReference('storage-type-large', $largeBox);

        // Storage types for Praha Jih
        $smallBoxJih = new StorageType(
            id: Uuid::v7(),
            name: 'Maly box',
            width: 100,
            height: 100,
            length: 100,
            pricePerWeek: 12000,   // 120 CZK (cheaper)
            pricePerMonth: 40000,  // 400 CZK
            place: $placePrahaJih,
            createdAt: $now,
        );
        $manager->persist($smallBoxJih);
        $this->addReference('storage-type-small-jih', $smallBoxJih);

        $mediumBoxJih = new StorageType(
            id: Uuid::v7(),
            name: 'Stredni box',
            width: 200,
            height: 200,
            length: 200,
            pricePerWeek: 30000,   // 300 CZK
            pricePerMonth: 100000, // 1000 CZK
            place: $placePrahaJih,
            createdAt: $now,
        );
        $manager->persist($mediumBoxJih);
        $this->addReference('storage-type-medium-jih', $mediumBoxJih);

        // Storage type for Brno
        $premiumBox = new StorageType(
            id: Uuid::v7(),
            name: 'Premium box',
            width: 500,   // 5m in cm
            height: 300,
            length: 600,
            pricePerWeek: 150000,  // 1500 CZK
            pricePerMonth: 500000, // 5000 CZK
            place: $placeBrno,
            createdAt: $now,
        );
        $manager->persist($premiumBox);
        $this->addReference('storage-type-premium', $premiumBox);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlaceFixtures::class,
        ];
    }
}
