<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Storage;
use App\Entity\StorageUnavailability;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class StorageUnavailabilityFixtures extends Fixture implements DependentFixtureInterface
{
    // Indefinite unavailability (no end date)
    public const REF_UNAVAILABILITY_INDEFINITE = 'unavailability-indefinite';

    // Fixed period unavailability (current)
    public const REF_UNAVAILABILITY_FIXED = 'unavailability-fixed';

    // Past unavailability (ended)
    public const REF_UNAVAILABILITY_PAST = 'unavailability-past';

    // Future unavailability (starts in the future)
    public const REF_UNAVAILABILITY_FUTURE = 'unavailability-future';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var User $landlord */
        $landlord = $this->getReference(UserFixtures::REF_LANDLORD, User::class);

        /** @var User $landlord2 */
        $landlord2 = $this->getReference(UserFixtures::REF_LANDLORD2, User::class);

        /** @var Storage $storageC2 */
        $storageC2 = $this->getReference(StorageFixtures::REF_LARGE_C2, Storage::class);

        /** @var Storage $storageA4 */
        $storageA4 = $this->getReference(StorageFixtures::REF_SMALL_A4, Storage::class);

        /** @var Storage $storageA5 */
        $storageA5 = $this->getReference(StorageFixtures::REF_SMALL_A5, Storage::class);

        /** @var Storage $storageO1 */
        $storageO1 = $this->getReference(StorageFixtures::REF_STANDARD_O1, Storage::class);

        // Indefinite unavailability - maintenance with no end date
        $unavailabilityIndefinite = new StorageUnavailability(
            id: Uuid::v7(),
            storage: $storageC2,
            startDate: $now->modify('-7 days'),
            endDate: null, // Indefinite
            reason: 'Rekonstrukce - oprava podlahy',
            createdBy: $landlord,
            createdAt: $now->modify('-7 days'),
        );
        $storageC2->markUnavailable($now->modify('-7 days'));
        $manager->persist($unavailabilityIndefinite);
        $this->addReference(self::REF_UNAVAILABILITY_INDEFINITE, $unavailabilityIndefinite);

        // Fixed period unavailability - currently active
        $unavailabilityFixed = new StorageUnavailability(
            id: Uuid::v7(),
            storage: $storageA4,
            startDate: $now->modify('-3 days'),
            endDate: $now->modify('+4 days'),
            reason: 'Preventivni udrzba',
            createdBy: $landlord,
            createdAt: $now->modify('-3 days'),
        );
        $storageA4->markUnavailable($now->modify('-3 days'));
        $manager->persist($unavailabilityFixed);
        $this->addReference(self::REF_UNAVAILABILITY_FIXED, $unavailabilityFixed);

        // Past unavailability - already ended
        $unavailabilityPast = new StorageUnavailability(
            id: Uuid::v7(),
            storage: $storageA5,
            startDate: $now->modify('-14 days'),
            endDate: $now->modify('-7 days'),
            reason: 'Vymena zamku',
            createdBy: $landlord,
            createdAt: $now->modify('-14 days'),
        );
        // Storage is available again (don't mark as unavailable)
        $manager->persist($unavailabilityPast);
        $this->addReference(self::REF_UNAVAILABILITY_PAST, $unavailabilityPast);

        // Future unavailability - starts in the future
        $unavailabilityFuture = new StorageUnavailability(
            id: Uuid::v7(),
            storage: $storageO1,
            startDate: $now->modify('+14 days'),
            endDate: $now->modify('+21 days'),
            reason: 'Planovana udrzba klimatizace',
            createdBy: $landlord2,
            createdAt: $now,
        );
        // Storage is still available (future unavailability)
        $manager->persist($unavailabilityFuture);
        $this->addReference(self::REF_UNAVAILABILITY_FUTURE, $unavailabilityFuture);

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
