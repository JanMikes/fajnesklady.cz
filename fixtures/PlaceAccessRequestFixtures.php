<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Place;
use App\Entity\PlaceAccessRequest;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class PlaceAccessRequestFixtures extends Fixture implements DependentFixtureInterface
{
    public const REF_PENDING_LANDLORD_BRNO = 'place-access-request-pending-landlord-brno';
    public const REF_APPROVED_LANDLORD2_OSTRAVA = 'place-access-request-approved-landlord2-ostrava';
    public const REF_DENIED_LANDLORD_PLZEN = 'place-access-request-denied-landlord-plzen';

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

        /** @var User $admin */
        $admin = $this->getReference(UserFixtures::REF_ADMIN, User::class);

        /** @var Place $brno */
        $brno = $this->getReference(PlaceFixtures::REF_BRNO, Place::class);

        /** @var Place $ostrava */
        $ostrava = $this->getReference(PlaceFixtures::REF_OSTRAVA, Place::class);

        /** @var Place $plzen */
        $plzen = $this->getReference(PlaceFixtures::REF_PLZEN, Place::class);

        // Pending request from landlord to Brno
        $pending = new PlaceAccessRequest(
            id: Uuid::v7(),
            place: $brno,
            requestedBy: $landlord,
            message: 'Rad bych skladoval v Brne.',
            createdAt: $now,
        );
        $pending->popEvents();
        $manager->persist($pending);
        $this->addReference(self::REF_PENDING_LANDLORD_BRNO, $pending);

        // Approved request from landlord2 to Ostrava (historical)
        $approved = new PlaceAccessRequest(
            id: Uuid::v7(),
            place: $ostrava,
            requestedBy: $landlord2,
            message: null,
            createdAt: $now,
        );
        $approved->approve($admin, $now);
        $approved->popEvents();
        $manager->persist($approved);
        $this->addReference(self::REF_APPROVED_LANDLORD2_OSTRAVA, $approved);

        // Denied request from landlord to Plzen
        $denied = new PlaceAccessRequest(
            id: Uuid::v7(),
            place: $plzen,
            requestedBy: $landlord,
            message: 'Chtel bych pristup do skladu v Plzni.',
            createdAt: $now,
        );
        $denied->deny($admin, $now);
        $denied->popEvents();
        $manager->persist($denied);
        $this->addReference(self::REF_DENIED_LANDLORD_PLZEN, $denied);

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
            PlaceAccessFixtures::class,
        ];
    }
}
