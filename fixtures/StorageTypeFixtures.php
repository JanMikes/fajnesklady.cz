<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\StorageType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class StorageTypeFixtures extends Fixture
{
    // Global storage types (no longer place-specific)
    public const REF_SMALL = 'storage-type-small';
    public const REF_MEDIUM = 'storage-type-medium';
    public const REF_LARGE = 'storage-type-large';
    public const REF_PREMIUM = 'storage-type-premium';
    public const REF_STANDARD = 'storage-type-standard';

    // Keep old references for backward compatibility in tests
    public const REF_SMALL_CENTRUM = self::REF_SMALL;
    public const REF_MEDIUM_CENTRUM = self::REF_MEDIUM;
    public const REF_LARGE_CENTRUM = self::REF_LARGE;
    public const REF_SMALL_JIH = self::REF_SMALL;
    public const REF_MEDIUM_JIH = self::REF_MEDIUM;
    public const REF_PREMIUM_BRNO = self::REF_PREMIUM;
    public const REF_STANDARD_OSTRAVA = self::REF_STANDARD;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // Global storage types with default prices
        $smallBox = new StorageType(
            id: Uuid::v7(),
            name: 'Maly box',
            innerWidth: 100,   // 1m in cm
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 15000,   // 150 CZK
            defaultPricePerMonth: 50000,  // 500 CZK
            createdAt: $now,
        );
        $manager->persist($smallBox);
        $this->addReference(self::REF_SMALL, $smallBox);

        $mediumBox = new StorageType(
            id: Uuid::v7(),
            name: 'Stredni box',
            innerWidth: 200,   // 2m in cm
            innerHeight: 200,
            innerLength: 200,
            defaultPricePerWeek: 35000,   // 350 CZK
            defaultPricePerMonth: 120000, // 1200 CZK
            createdAt: $now,
            outerWidth: 210,
            outerHeight: 210,
            outerLength: 210,
        );
        $manager->persist($mediumBox);
        $this->addReference(self::REF_MEDIUM, $mediumBox);

        $largeBox = new StorageType(
            id: Uuid::v7(),
            name: 'Velky box',
            innerWidth: 300,   // 3m in cm
            innerHeight: 250,
            innerLength: 400,
            defaultPricePerWeek: 80000,   // 800 CZK
            defaultPricePerMonth: 280000, // 2800 CZK
            createdAt: $now,
            outerWidth: 320,
            outerHeight: 270,
            outerLength: 420,
        );
        $manager->persist($largeBox);
        $this->addReference(self::REF_LARGE, $largeBox);

        $premiumBox = new StorageType(
            id: Uuid::v7(),
            name: 'Premium box',
            innerWidth: 500,   // 5m in cm
            innerHeight: 300,
            innerLength: 600,
            defaultPricePerWeek: 150000,  // 1500 CZK
            defaultPricePerMonth: 500000, // 5000 CZK
            createdAt: $now,
            outerWidth: 520,
            outerHeight: 320,
            outerLength: 620,
        );
        $manager->persist($premiumBox);
        $this->addReference(self::REF_PREMIUM, $premiumBox);

        $standardBox = new StorageType(
            id: Uuid::v7(),
            name: 'Standardni box',
            innerWidth: 150,
            innerHeight: 150,
            innerLength: 150,
            defaultPricePerWeek: 20000,   // 200 CZK
            defaultPricePerMonth: 70000,  // 700 CZK
            createdAt: $now,
        );
        $manager->persist($standardBox);
        $this->addReference(self::REF_STANDARD, $standardBox);

        $manager->flush();
    }
}
