<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Storage;
use App\Entity\StorageType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class StorageFixtures extends Fixture implements DependentFixtureInterface
{
    // Praha Centrum - Small boxes (available for tests)
    public const REF_SMALL_A1 = 'storage-small-a1';
    public const REF_SMALL_A2 = 'storage-small-a2';
    public const REF_SMALL_A3 = 'storage-small-a3';
    public const REF_SMALL_A4 = 'storage-small-a4';
    public const REF_SMALL_A5 = 'storage-small-a5';

    // Praha Centrum - Medium boxes
    public const REF_MEDIUM_B1 = 'storage-medium-b1';
    public const REF_MEDIUM_B2 = 'storage-medium-b2';
    public const REF_MEDIUM_B3 = 'storage-medium-b3';

    // Praha Centrum - Large boxes
    public const REF_LARGE_C1 = 'storage-large-c1';
    public const REF_LARGE_C2 = 'storage-large-c2';

    // Praha Jih - Small boxes
    public const REF_SMALL_D1 = 'storage-small-d1';
    public const REF_SMALL_D2 = 'storage-small-d2';
    public const REF_SMALL_D3 = 'storage-small-d3';

    // Praha Jih - Medium boxes
    public const REF_MEDIUM_E1 = 'storage-medium-e1';
    public const REF_MEDIUM_E2 = 'storage-medium-e2';

    // Brno - Premium boxes
    public const REF_PREMIUM_P1 = 'storage-premium-p1';
    public const REF_PREMIUM_P2 = 'storage-premium-p2';

    // Ostrava - Standard boxes (landlord2)
    public const REF_STANDARD_O1 = 'storage-standard-o1';
    public const REF_STANDARD_O2 = 'storage-standard-o2';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var StorageType $smallType */
        $smallType = $this->getReference(StorageTypeFixtures::REF_SMALL_CENTRUM, StorageType::class);

        /** @var StorageType $mediumType */
        $mediumType = $this->getReference(StorageTypeFixtures::REF_MEDIUM_CENTRUM, StorageType::class);

        /** @var StorageType $largeType */
        $largeType = $this->getReference(StorageTypeFixtures::REF_LARGE_CENTRUM, StorageType::class);

        /** @var StorageType $smallJihType */
        $smallJihType = $this->getReference(StorageTypeFixtures::REF_SMALL_JIH, StorageType::class);

        /** @var StorageType $mediumJihType */
        $mediumJihType = $this->getReference(StorageTypeFixtures::REF_MEDIUM_JIH, StorageType::class);

        /** @var StorageType $premiumType */
        $premiumType = $this->getReference(StorageTypeFixtures::REF_PREMIUM_BRNO, StorageType::class);

        /** @var StorageType $standardOstravaType */
        $standardOstravaType = $this->getReference(StorageTypeFixtures::REF_STANDARD_OSTRAVA, StorageType::class);

        // Small boxes A1-A5 in Praha Centrum
        $smallRefs = [self::REF_SMALL_A1, self::REF_SMALL_A2, self::REF_SMALL_A3, self::REF_SMALL_A4, self::REF_SMALL_A5];
        for ($i = 1; $i <= 5; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "A{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 110, 'y' => 50, 'width' => 100, 'height' => 100, 'rotation' => 0],
                storageType: $smallType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($smallRefs[$i - 1], $storage);
        }

        // Medium boxes B1-B3 in Praha Centrum
        $mediumRefs = [self::REF_MEDIUM_B1, self::REF_MEDIUM_B2, self::REF_MEDIUM_B3];
        for ($i = 1; $i <= 3; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "B{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 220, 'y' => 200, 'width' => 200, 'height' => 200, 'rotation' => 0],
                storageType: $mediumType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($mediumRefs[$i - 1], $storage);
        }

        // Large boxes C1-C2 in Praha Centrum
        $largeRefs = [self::REF_LARGE_C1, self::REF_LARGE_C2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "C{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 420, 'y' => 450, 'width' => 400, 'height' => 300, 'rotation' => 0],
                storageType: $largeType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($largeRefs[$i - 1], $storage);
        }

        // Small boxes D1-D3 in Praha Jih
        $smallJihRefs = [self::REF_SMALL_D1, self::REF_SMALL_D2, self::REF_SMALL_D3];
        for ($i = 1; $i <= 3; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "D{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 110, 'y' => 50, 'width' => 100, 'height' => 100, 'rotation' => 0],
                storageType: $smallJihType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($smallJihRefs[$i - 1], $storage);
        }

        // Medium boxes E1-E2 in Praha Jih
        $mediumJihRefs = [self::REF_MEDIUM_E1, self::REF_MEDIUM_E2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "E{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 220, 'y' => 200, 'width' => 200, 'height' => 200, 'rotation' => 0],
                storageType: $mediumJihType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($mediumJihRefs[$i - 1], $storage);
        }

        // Premium boxes P1-P2 in Brno
        $premiumRefs = [self::REF_PREMIUM_P1, self::REF_PREMIUM_P2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "P{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 620, 'y' => 50, 'width' => 600, 'height' => 500, 'rotation' => 0],
                storageType: $premiumType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($premiumRefs[$i - 1], $storage);
        }

        // Standard boxes O1-O2 in Ostrava (landlord2)
        $ostravaRefs = [self::REF_STANDARD_O1, self::REF_STANDARD_O2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "O{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 160, 'y' => 50, 'width' => 150, 'height' => 150, 'rotation' => 0],
                storageType: $standardOstravaType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($ostravaRefs[$i - 1], $storage);
        }

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            StorageTypeFixtures::class,
        ];
    }
}
