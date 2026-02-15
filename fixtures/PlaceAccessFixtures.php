<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Place;
use App\Entity\PlaceAccess;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class PlaceAccessFixtures extends Fixture implements DependentFixtureInterface
{
    public const REF_LANDLORD_PRAHA_CENTRUM = 'place-access-landlord-praha-centrum';
    public const REF_LANDLORD_PRAHA_JIH = 'place-access-landlord-praha-jih';
    public const REF_LANDLORD2_OSTRAVA = 'place-access-landlord2-ostrava';

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

        /** @var Place $prahaCentrum */
        $prahaCentrum = $this->getReference(PlaceFixtures::REF_PRAHA_CENTRUM, Place::class);

        /** @var Place $prahaJih */
        $prahaJih = $this->getReference(PlaceFixtures::REF_PRAHA_JIH, Place::class);

        /** @var Place $ostrava */
        $ostrava = $this->getReference(PlaceFixtures::REF_OSTRAVA, Place::class);

        // Landlord has access to Praha Centrum and Praha Jih
        $access1 = new PlaceAccess(
            id: Uuid::v7(),
            place: $prahaCentrum,
            user: $landlord,
            grantedAt: $now,
        );
        $manager->persist($access1);
        $this->addReference(self::REF_LANDLORD_PRAHA_CENTRUM, $access1);

        $access2 = new PlaceAccess(
            id: Uuid::v7(),
            place: $prahaJih,
            user: $landlord,
            grantedAt: $now,
        );
        $manager->persist($access2);
        $this->addReference(self::REF_LANDLORD_PRAHA_JIH, $access2);

        // Landlord2 has access to Ostrava
        $access3 = new PlaceAccess(
            id: Uuid::v7(),
            place: $ostrava,
            user: $landlord2,
            grantedAt: $now,
        );
        $manager->persist($access3);
        $this->addReference(self::REF_LANDLORD2_OSTRAVA, $access3);

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
        ];
    }
}
