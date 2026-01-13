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
    // Reference constants - Praha Centrum
    public const REF_SMALL_CENTRUM = 'storage-type-small-centrum';
    public const REF_MEDIUM_CENTRUM = 'storage-type-medium-centrum';
    public const REF_LARGE_CENTRUM = 'storage-type-large-centrum';

    // Reference constants - Praha Jih
    public const REF_SMALL_JIH = 'storage-type-small-jih';
    public const REF_MEDIUM_JIH = 'storage-type-medium-jih';

    // Reference constants - Brno
    public const REF_PREMIUM_BRNO = 'storage-type-premium-brno';

    // Reference constants - Ostrava
    public const REF_STANDARD_OSTRAVA = 'storage-type-standard-ostrava';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var Place $placePrahaCentrum */
        $placePrahaCentrum = $this->getReference(PlaceFixtures::REF_PRAHA_CENTRUM, Place::class);

        /** @var Place $placePrahaJih */
        $placePrahaJih = $this->getReference(PlaceFixtures::REF_PRAHA_JIH, Place::class);

        /** @var Place $placeBrno */
        $placeBrno = $this->getReference(PlaceFixtures::REF_BRNO, Place::class);

        /** @var Place $placeOstrava */
        $placeOstrava = $this->getReference(PlaceFixtures::REF_OSTRAVA, Place::class);

        // Storage types for Praha Centrum
        $smallBox = new StorageType(
            id: Uuid::v7(),
            name: 'Maly box',
            innerWidth: 100,   // 1m in cm
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 15000,   // 150 CZK
            pricePerMonth: 50000,  // 500 CZK
            place: $placePrahaCentrum,
            createdAt: $now,
        );
        $manager->persist($smallBox);
        $this->addReference(self::REF_SMALL_CENTRUM, $smallBox);

        $mediumBox = new StorageType(
            id: Uuid::v7(),
            name: 'Stredni box',
            innerWidth: 200,   // 2m in cm
            innerHeight: 200,
            innerLength: 200,
            pricePerWeek: 35000,   // 350 CZK
            pricePerMonth: 120000, // 1200 CZK
            place: $placePrahaCentrum,
            createdAt: $now,
            outerWidth: 210,
            outerHeight: 210,
            outerLength: 210,
        );
        $manager->persist($mediumBox);
        $this->addReference(self::REF_MEDIUM_CENTRUM, $mediumBox);

        $largeBox = new StorageType(
            id: Uuid::v7(),
            name: 'Velky box',
            innerWidth: 300,   // 3m in cm
            innerHeight: 250,
            innerLength: 400,
            pricePerWeek: 80000,   // 800 CZK
            pricePerMonth: 280000, // 2800 CZK
            place: $placePrahaCentrum,
            createdAt: $now,
            outerWidth: 320,
            outerHeight: 270,
            outerLength: 420,
        );
        $manager->persist($largeBox);
        $this->addReference(self::REF_LARGE_CENTRUM, $largeBox);

        // Storage types for Praha Jih
        $smallBoxJih = new StorageType(
            id: Uuid::v7(),
            name: 'Maly box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 12000,   // 120 CZK (cheaper)
            pricePerMonth: 40000,  // 400 CZK
            place: $placePrahaJih,
            createdAt: $now,
        );
        $manager->persist($smallBoxJih);
        $this->addReference(self::REF_SMALL_JIH, $smallBoxJih);

        $mediumBoxJih = new StorageType(
            id: Uuid::v7(),
            name: 'Stredni box',
            innerWidth: 200,
            innerHeight: 200,
            innerLength: 200,
            pricePerWeek: 30000,   // 300 CZK
            pricePerMonth: 100000, // 1000 CZK
            place: $placePrahaJih,
            createdAt: $now,
        );
        $manager->persist($mediumBoxJih);
        $this->addReference(self::REF_MEDIUM_JIH, $mediumBoxJih);

        // Storage type for Brno
        $premiumBox = new StorageType(
            id: Uuid::v7(),
            name: 'Premium box',
            innerWidth: 500,   // 5m in cm
            innerHeight: 300,
            innerLength: 600,
            pricePerWeek: 150000,  // 1500 CZK
            pricePerMonth: 500000, // 5000 CZK
            place: $placeBrno,
            createdAt: $now,
            outerWidth: 520,
            outerHeight: 320,
            outerLength: 620,
        );
        $manager->persist($premiumBox);
        $this->addReference(self::REF_PREMIUM_BRNO, $premiumBox);

        // Storage type for Ostrava (landlord2)
        $standardOstrava = new StorageType(
            id: Uuid::v7(),
            name: 'Standardni box',
            innerWidth: 150,
            innerHeight: 150,
            innerLength: 150,
            pricePerWeek: 20000,   // 200 CZK
            pricePerMonth: 70000,  // 700 CZK
            place: $placeOstrava,
            createdAt: $now,
        );
        $manager->persist($standardOstrava);
        $this->addReference(self::REF_STANDARD_OSTRAVA, $standardOstrava);

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
