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
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var StorageType $smallType */
        $smallType = $this->getReference('storage-type-small', StorageType::class);

        /** @var StorageType $mediumType */
        $mediumType = $this->getReference('storage-type-medium', StorageType::class);

        /** @var StorageType $largeType */
        $largeType = $this->getReference('storage-type-large', StorageType::class);

        /** @var StorageType $smallJihType */
        $smallJihType = $this->getReference('storage-type-small-jih', StorageType::class);

        /** @var StorageType $mediumJihType */
        $mediumJihType = $this->getReference('storage-type-medium-jih', StorageType::class);

        /** @var StorageType $premiumType */
        $premiumType = $this->getReference('storage-type-premium', StorageType::class);

        // Small boxes A1-A5 in Praha Centrum
        for ($i = 1; $i <= 5; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "A{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 110, 'y' => 50, 'width' => 100, 'height' => 100, 'rotation' => 0],
                storageType: $smallType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference("storage-small-a{$i}", $storage);
        }

        // Medium boxes B1-B3 in Praha Centrum
        for ($i = 1; $i <= 3; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "B{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 220, 'y' => 200, 'width' => 200, 'height' => 200, 'rotation' => 0],
                storageType: $mediumType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference("storage-medium-b{$i}", $storage);
        }

        // Large boxes C1-C2 in Praha Centrum
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "C{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 420, 'y' => 450, 'width' => 400, 'height' => 300, 'rotation' => 0],
                storageType: $largeType,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference("storage-large-c{$i}", $storage);
        }

        // Small boxes D1-D3 in Praha Jih
        for ($i = 1; $i <= 3; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "D{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 110, 'y' => 50, 'width' => 100, 'height' => 100, 'rotation' => 0],
                storageType: $smallJihType,
                createdAt: $now,
            );
            $manager->persist($storage);
        }

        // Medium boxes E1-E2 in Praha Jih
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "E{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 220, 'y' => 200, 'width' => 200, 'height' => 200, 'rotation' => 0],
                storageType: $mediumJihType,
                createdAt: $now,
            );
            $manager->persist($storage);
        }

        // Premium boxes P1-P2 in Brno
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "P{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 620, 'y' => 50, 'width' => 600, 'height' => 500, 'rotation' => 0],
                storageType: $premiumType,
                createdAt: $now,
            );
            $manager->persist($storage);
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
