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
            address: 'Revolucni 1, 110 00 Praha 1',
            description: 'Moderni skladovaci prostory v centru Prahy s 24/7 pristupem.',
            owner: $landlord,
            createdAt: $now,
        );
        $manager->persist($place1);

        $place2 = new Place(
            id: Uuid::v7(),
            name: 'Sklad Praha - Jiznimesto',
            address: 'Roztylska 42, 148 00 Praha 4',
            description: 'Skladovaci boxy ruznych velikosti s parkovanim zdarma.',
            owner: $landlord,
            createdAt: $now,
        );
        $manager->persist($place2);

        // Place for admin (to test admin can see all)
        $place3 = new Place(
            id: Uuid::v7(),
            name: 'Sklad Brno',
            address: 'Masarykova 15, 602 00 Brno',
            description: null,
            owner: $admin,
            createdAt: $now,
        );
        $manager->persist($place3);

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
