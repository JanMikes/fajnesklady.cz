<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class UserFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // Regular verified user
        $user = new User(
            id: Uuid::v7(),
            email: 'user@example.com',
            password: '',
            firstName: 'Jan',
            lastName: 'Novak',
            createdAt: $now,
        );
        $user->changePassword($this->passwordHasher->hashPassword($user, 'password'), $now);
        $user->markAsVerified($now);
        $manager->persist($user);
        $this->addReference('user-regular', $user);

        // Unverified user
        $unverified = new User(
            id: Uuid::v7(),
            email: 'unverified@example.com',
            password: '',
            firstName: 'Petr',
            lastName: 'Svoboda',
            createdAt: $now,
        );
        $unverified->changePassword($this->passwordHasher->hashPassword($unverified, 'password'), $now);
        // Don't mark as verified
        $manager->persist($unverified);

        // Landlord user
        $landlord = new User(
            id: Uuid::v7(),
            email: 'landlord@example.com',
            password: '',
            firstName: 'Marie',
            lastName: 'Skladova',
            createdAt: $now,
        );
        $landlord->changePassword($this->passwordHasher->hashPassword($landlord, 'password'), $now);
        $landlord->markAsVerified($now);
        $landlord->changeRole(UserRole::LANDLORD, $now);
        $landlord->updateProfile('Marie', 'Skladova', '+420777123456', $now);
        $manager->persist($landlord);
        $this->addReference('user-landlord', $landlord);

        // Admin user
        $admin = new User(
            id: Uuid::v7(),
            email: 'admin@example.com',
            password: '',
            firstName: 'Admin',
            lastName: 'System',
            createdAt: $now,
        );
        $admin->changePassword($this->passwordHasher->hashPassword($admin, 'password'), $now);
        $admin->markAsVerified($now);
        $admin->changeRole(UserRole::ADMIN, $now);
        $manager->persist($admin);
        $this->addReference('user-admin', $admin);

        $manager->flush();
    }
}
