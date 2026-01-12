<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Place;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class PlaceFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var User $landlord */
        $landlord = $this->getReference('user-landlord', User::class);

        /** @var User $admin */
        $admin = $this->getReference('user-admin', User::class);

        // Places for landlord
        $place1 = new Place(
            id: Uuid::v7(),
            name: 'Sklad Praha - Centrum',
            address: 'Revolucni 1',
            city: 'Praha 1',
            postalCode: '110 00',
            description: 'Moderni skladovaci prostory v centru Prahy s 24/7 pristupem.',
            owner: $landlord,
            createdAt: $now,
        );
        $place1->updateLocation('50.0904272', '14.4314139', $now);
        $manager->persist($place1);
        $this->addReference('place-praha-centrum', $place1);

        $place2 = new Place(
            id: Uuid::v7(),
            name: 'Sklad Praha - Jiznimesto',
            address: 'Roztylska 42',
            city: 'Praha 4',
            postalCode: '148 00',
            description: 'Skladovaci boxy ruznych velikosti s parkovanim zdarma.',
            owner: $landlord,
            createdAt: $now,
        );
        $place2->updateLocation('50.0312889', '14.4949583', $now);
        $manager->persist($place2);
        $this->addReference('place-praha-jih', $place2);

        // Place for admin (to test admin can see all)
        $place3 = new Place(
            id: Uuid::v7(),
            name: 'Sklad Brno',
            address: 'Masarykova 15',
            city: 'Brno',
            postalCode: '602 00',
            description: null,
            owner: $admin,
            createdAt: $now,
        );
        $place3->updateLocation('49.1950602', '16.6068371', $now);
        $manager->persist($place3);
        $this->addReference('place-brno', $place3);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
