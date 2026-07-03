<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Enum\StorageCodeUsageType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class PlaceStorageCodeUsageFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var Place $placePrahaCentrum */
        $placePrahaCentrum = $this->getReference(PlaceFixtures::REF_PRAHA_CENTRUM, Place::class);

        foreach (['0042', '0577'] as $code) {
            $manager->persist(new PlaceStorageCodeUsage(
                id: Uuid::v7(),
                place: $placePrahaCentrum,
                code: $code,
                type: StorageCodeUsageType::USED,
                note: null,
                usedAt: $now,
            ));
        }

        $manager->persist(new PlaceStorageCodeUsage(
            id: Uuid::v7(),
            place: $placePrahaCentrum,
            code: '9999',
            type: StorageCodeUsageType::EXCLUDED,
            note: 'Servisní kód zámku',
            usedAt: $now,
        ));

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlaceFixtures::class,
            StorageFixtures::class,
        ];
    }
}
