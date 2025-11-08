<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\User\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Admin user
        $admin = User::create(
            email: 'admin@example.com',
            name: 'Admin User',
            password: '', // Will be hashed below
        );
        $admin->changePassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $admin->markAsVerified();
        $admin->changeRole('ROLE_ADMIN');
        $manager->persist($admin);

        // Regular verified user
        $user = User::create(
            email: 'user@example.com',
            name: 'Regular User',
            password: '', // Will be hashed below
        );
        $user->changePassword($this->passwordHasher->hashPassword($user, 'user123'));
        $user->markAsVerified();
        $manager->persist($user);

        // Unverified user
        $unverified = User::create(
            email: 'unverified@example.com',
            name: 'Unverified User',
            password: '', // Will be hashed below
        );
        $unverified->changePassword($this->passwordHasher->hashPassword($unverified, 'user123'));
        // Don't mark as verified
        $manager->persist($unverified);

        $manager->flush();
    }
}
