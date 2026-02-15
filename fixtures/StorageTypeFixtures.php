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
    // Praha Centrum
    public const REF_SMALL_CENTRUM = 'storage-type-small-centrum';
    public const REF_MEDIUM_CENTRUM = 'storage-type-medium-centrum';
    public const REF_LARGE_CENTRUM = 'storage-type-large-centrum';
    public const REF_CUSTOM_CENTRUM = 'storage-type-custom-centrum';

    // Praha Jih
    public const REF_SMALL_JIH = 'storage-type-small-jih';
    public const REF_MEDIUM_JIH = 'storage-type-medium-jih';

    // Brno
    public const REF_PREMIUM_BRNO = 'storage-type-premium-brno';

    // Ostrava
    public const REF_STANDARD_OSTRAVA = 'storage-type-standard-ostrava';

    // Backward compatibility aliases
    public const REF_SMALL = self::REF_SMALL_CENTRUM;
    public const REF_MEDIUM = self::REF_MEDIUM_CENTRUM;
    public const REF_LARGE = self::REF_LARGE_CENTRUM;
    public const REF_PREMIUM = self::REF_PREMIUM_BRNO;
    public const REF_STANDARD = self::REF_STANDARD_OSTRAVA;
    public const REF_CUSTOM = self::REF_CUSTOM_CENTRUM;

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

        // Praha Centrum storage types
        $smallCentrum = new StorageType(
            id: Uuid::v7(),
            place: $placePrahaCentrum,
            name: 'Maly box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 15000,
            defaultPricePerMonth: 50000,
            createdAt: $now,
        );
        $manager->persist($smallCentrum);
        $this->addReference(self::REF_SMALL_CENTRUM, $smallCentrum);

        $mediumCentrum = new StorageType(
            id: Uuid::v7(),
            place: $placePrahaCentrum,
            name: 'Stredni box',
            innerWidth: 200,
            innerHeight: 200,
            innerLength: 200,
            defaultPricePerWeek: 35000,
            defaultPricePerMonth: 120000,
            createdAt: $now,
            outerWidth: 210,
            outerHeight: 210,
            outerLength: 210,
        );
        $manager->persist($mediumCentrum);
        $this->addReference(self::REF_MEDIUM_CENTRUM, $mediumCentrum);

        $largeCentrum = new StorageType(
            id: Uuid::v7(),
            place: $placePrahaCentrum,
            name: 'Velky box',
            innerWidth: 300,
            innerHeight: 250,
            innerLength: 400,
            defaultPricePerWeek: 80000,
            defaultPricePerMonth: 280000,
            createdAt: $now,
            outerWidth: 320,
            outerHeight: 270,
            outerLength: 420,
        );
        $manager->persist($largeCentrum);
        $this->addReference(self::REF_LARGE_CENTRUM, $largeCentrum);

        // Non-uniform storage type at Praha Centrum
        $customCentrum = new StorageType(
            id: Uuid::v7(),
            place: $placePrahaCentrum,
            name: 'Custom box',
            innerWidth: 250,
            innerHeight: 220,
            innerLength: 300,
            defaultPricePerWeek: 40000,
            defaultPricePerMonth: 140000,
            createdAt: $now,
            uniformStorages: false,
            outerWidth: 260,
            outerHeight: 230,
            outerLength: 310,
        );
        $manager->persist($customCentrum);
        $this->addReference(self::REF_CUSTOM_CENTRUM, $customCentrum);

        // Praha Jih storage types
        $smallJih = new StorageType(
            id: Uuid::v7(),
            place: $placePrahaJih,
            name: 'Maly box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 12000,
            defaultPricePerMonth: 40000,
            createdAt: $now,
        );
        $manager->persist($smallJih);
        $this->addReference(self::REF_SMALL_JIH, $smallJih);

        $mediumJih = new StorageType(
            id: Uuid::v7(),
            place: $placePrahaJih,
            name: 'Stredni box',
            innerWidth: 200,
            innerHeight: 200,
            innerLength: 200,
            defaultPricePerWeek: 30000,
            defaultPricePerMonth: 100000,
            createdAt: $now,
            outerWidth: 210,
            outerHeight: 210,
            outerLength: 210,
        );
        $manager->persist($mediumJih);
        $this->addReference(self::REF_MEDIUM_JIH, $mediumJih);

        // Brno
        $premiumBrno = new StorageType(
            id: Uuid::v7(),
            place: $placeBrno,
            name: 'Premium box',
            innerWidth: 500,
            innerHeight: 300,
            innerLength: 600,
            defaultPricePerWeek: 150000,
            defaultPricePerMonth: 500000,
            createdAt: $now,
            outerWidth: 520,
            outerHeight: 320,
            outerLength: 620,
        );
        $manager->persist($premiumBrno);
        $this->addReference(self::REF_PREMIUM_BRNO, $premiumBrno);

        // Ostrava
        $standardOstrava = new StorageType(
            id: Uuid::v7(),
            place: $placeOstrava,
            name: 'Standardni box',
            innerWidth: 150,
            innerHeight: 150,
            innerLength: 150,
            defaultPricePerWeek: 20000,
            defaultPricePerMonth: 70000,
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
