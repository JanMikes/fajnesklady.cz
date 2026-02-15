<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
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

    // Praha Centrum - Custom boxes (non-uniform with per-storage pricing)
    public const REF_CUSTOM_X1 = 'storage-custom-x1';
    public const REF_CUSTOM_X2 = 'storage-custom-x2';
    public const REF_CUSTOM_X3 = 'storage-custom-x3';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // Get places
        /** @var Place $placePrahaCentrum */
        $placePrahaCentrum = $this->getReference(PlaceFixtures::REF_PRAHA_CENTRUM, Place::class);

        /** @var Place $placePrahaJih */
        $placePrahaJih = $this->getReference(PlaceFixtures::REF_PRAHA_JIH, Place::class);

        /** @var Place $placeBrno */
        $placeBrno = $this->getReference(PlaceFixtures::REF_BRNO, Place::class);

        /** @var Place $placeOstrava */
        $placeOstrava = $this->getReference(PlaceFixtures::REF_OSTRAVA, Place::class);

        // Get users for ownership
        /** @var User $landlord */
        $landlord = $this->getReference(UserFixtures::REF_LANDLORD, User::class);

        /** @var User $landlord2 */
        $landlord2 = $this->getReference(UserFixtures::REF_LANDLORD2, User::class);

        // Get storage types (place-specific)
        /** @var StorageType $smallTypeCentrum */
        $smallTypeCentrum = $this->getReference(StorageTypeFixtures::REF_SMALL_CENTRUM, StorageType::class);

        /** @var StorageType $mediumTypeCentrum */
        $mediumTypeCentrum = $this->getReference(StorageTypeFixtures::REF_MEDIUM_CENTRUM, StorageType::class);

        /** @var StorageType $largeTypeCentrum */
        $largeTypeCentrum = $this->getReference(StorageTypeFixtures::REF_LARGE_CENTRUM, StorageType::class);

        /** @var StorageType $smallTypeJih */
        $smallTypeJih = $this->getReference(StorageTypeFixtures::REF_SMALL_JIH, StorageType::class);

        /** @var StorageType $mediumTypeJih */
        $mediumTypeJih = $this->getReference(StorageTypeFixtures::REF_MEDIUM_JIH, StorageType::class);

        /** @var StorageType $premiumType */
        $premiumType = $this->getReference(StorageTypeFixtures::REF_PREMIUM_BRNO, StorageType::class);

        /** @var StorageType $standardType */
        $standardType = $this->getReference(StorageTypeFixtures::REF_STANDARD_OSTRAVA, StorageType::class);

        /** @var StorageType $customType */
        $customType = $this->getReference(StorageTypeFixtures::REF_CUSTOM_CENTRUM, StorageType::class);

        // Small boxes A1-A5 in Praha Centrum (owned by landlord)
        $smallRefs = [self::REF_SMALL_A1, self::REF_SMALL_A2, self::REF_SMALL_A3, self::REF_SMALL_A4, self::REF_SMALL_A5];
        for ($i = 1; $i <= 5; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "A{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 110, 'y' => 50, 'width' => 100, 'height' => 100, 'rotation' => 0],
                storageType: $smallTypeCentrum,
                place: $placePrahaCentrum,
                createdAt: $now,
                owner: $landlord,
            );
            $manager->persist($storage);
            $this->addReference($smallRefs[$i - 1], $storage);
        }

        // Medium boxes B1-B3 in Praha Centrum (owned by landlord)
        $mediumRefs = [self::REF_MEDIUM_B1, self::REF_MEDIUM_B2, self::REF_MEDIUM_B3];
        for ($i = 1; $i <= 3; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "B{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 220, 'y' => 200, 'width' => 200, 'height' => 200, 'rotation' => 0],
                storageType: $mediumTypeCentrum,
                place: $placePrahaCentrum,
                createdAt: $now,
                owner: $landlord,
            );
            $manager->persist($storage);
            $this->addReference($mediumRefs[$i - 1], $storage);
        }

        // Large boxes C1-C2 in Praha Centrum (owned by landlord)
        $largeRefs = [self::REF_LARGE_C1, self::REF_LARGE_C2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "C{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 420, 'y' => 450, 'width' => 400, 'height' => 300, 'rotation' => 0],
                storageType: $largeTypeCentrum,
                place: $placePrahaCentrum,
                createdAt: $now,
                owner: $landlord,
            );
            $manager->persist($storage);
            $this->addReference($largeRefs[$i - 1], $storage);
        }

        // Small boxes D1-D3 in Praha Jih (owned by landlord)
        $smallJihRefs = [self::REF_SMALL_D1, self::REF_SMALL_D2, self::REF_SMALL_D3];
        for ($i = 1; $i <= 3; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "D{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 110, 'y' => 50, 'width' => 100, 'height' => 100, 'rotation' => 0],
                storageType: $smallTypeJih,
                place: $placePrahaJih,
                createdAt: $now,
                owner: $landlord,
            );
            $manager->persist($storage);
            $this->addReference($smallJihRefs[$i - 1], $storage);
        }

        // Medium boxes E1-E2 in Praha Jih (owned by landlord)
        $mediumJihRefs = [self::REF_MEDIUM_E1, self::REF_MEDIUM_E2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "E{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 220, 'y' => 200, 'width' => 200, 'height' => 200, 'rotation' => 0],
                storageType: $mediumTypeJih,
                place: $placePrahaJih,
                createdAt: $now,
                owner: $landlord,
            );
            $manager->persist($storage);
            $this->addReference($mediumJihRefs[$i - 1], $storage);
        }

        // Premium boxes P1-P2 in Brno (no owner - unassigned)
        $premiumRefs = [self::REF_PREMIUM_P1, self::REF_PREMIUM_P2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "P{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 620, 'y' => 50, 'width' => 600, 'height' => 500, 'rotation' => 0],
                storageType: $premiumType,
                place: $placeBrno,
                createdAt: $now,
            );
            $manager->persist($storage);
            $this->addReference($premiumRefs[$i - 1], $storage);
        }

        // Standard boxes O1-O2 in Ostrava (owned by landlord2)
        $ostravaRefs = [self::REF_STANDARD_O1, self::REF_STANDARD_O2];
        for ($i = 1; $i <= 2; ++$i) {
            $storage = new Storage(
                id: Uuid::v7(),
                number: "O{$i}",
                coordinates: ['x' => 50 + ($i - 1) * 160, 'y' => 50, 'width' => 150, 'height' => 150, 'rotation' => 0],
                storageType: $standardType,
                place: $placeOstrava,
                createdAt: $now,
                owner: $landlord2,
            );
            $manager->persist($storage);
            $this->addReference($ostravaRefs[$i - 1], $storage);
        }

        // Custom boxes X1-X3 in Praha Centrum (non-uniform with per-storage pricing)
        // X1 - Custom price: 350 CZK/week, 1200 CZK/month (lower than default)
        $storageX1 = new Storage(
            id: Uuid::v7(),
            number: 'X1',
            coordinates: ['x' => 700, 'y' => 50, 'width' => 250, 'height' => 220, 'rotation' => 0],
            storageType: $customType,
            place: $placePrahaCentrum,
            createdAt: $now,
            owner: $landlord,
        );
        $storageX1->updatePrices(35000, 120000, $now); // 350 CZK/week, 1200 CZK/month
        $manager->persist($storageX1);
        $this->addReference(self::REF_CUSTOM_X1, $storageX1);

        // X2 - Custom price: 500 CZK/week, 1800 CZK/month (higher than default)
        $storageX2 = new Storage(
            id: Uuid::v7(),
            number: 'X2',
            coordinates: ['x' => 700, 'y' => 300, 'width' => 250, 'height' => 220, 'rotation' => 0],
            storageType: $customType,
            place: $placePrahaCentrum,
            createdAt: $now,
            owner: $landlord,
        );
        $storageX2->updatePrices(50000, 180000, $now); // 500 CZK/week, 1800 CZK/month
        $manager->persist($storageX2);
        $this->addReference(self::REF_CUSTOM_X2, $storageX2);

        // X3 - Uses default storage type pricing (no custom price)
        $storageX3 = new Storage(
            id: Uuid::v7(),
            number: 'X3',
            coordinates: ['x' => 700, 'y' => 550, 'width' => 250, 'height' => 220, 'rotation' => 0],
            storageType: $customType,
            place: $placePrahaCentrum,
            createdAt: $now,
            owner: $landlord,
        );
        // No updatePrices call - uses default storage type prices (400 CZK/week, 1400 CZK/month)
        $manager->persist($storageX3);
        $this->addReference(self::REF_CUSTOM_X3, $storageX3);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            PlaceFixtures::class,
            StorageTypeFixtures::class,
        ];
    }
}
