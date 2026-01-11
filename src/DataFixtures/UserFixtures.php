<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Enum\UserRole;
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
        // Regular verified user
        $user = User::create(
            email: 'user@example.com',
            name: 'Regular User',
            password: '', // Will be hashed below
        );
        $user->changePassword($this->passwordHasher->hashPassword($user, 'password'));
        $user->markAsVerified();
        $manager->persist($user);

        // Unverified user
        $unverified = User::create(
            email: 'unverified@example.com',
            name: 'Unverified User',
            password: '', // Will be hashed below
        );
        $unverified->changePassword($this->passwordHasher->hashPassword($unverified, 'password'));
        // Don't mark as verified
        $manager->persist($unverified);

        $manager->flush();

        // Admin user - role set manually via SQL since we removed changeRole()
        // To create an admin: UPDATE users SET roles = '["ROLE_USER", "ROLE_ADMIN"]' WHERE email = 'admin@example.com';
        $admin = User::create(
            email: 'admin@example.com',
            name: 'Admin User',
            password: '', // Will be hashed below
        );
        $admin->changePassword($this->passwordHasher->hashPassword($admin, 'password'));
        $admin->markAsVerified();
        $manager->persist($admin);
        $manager->flush();

        // Manually set admin role via SQL
        if ($manager instanceof \Doctrine\ORM\EntityManager) {
            $connection = $manager->getConnection();
            $connection->executeStatement(
                'UPDATE users SET roles = :roles WHERE email = :email',
                [
                    'roles' => json_encode([UserRole::USER->value, UserRole::ADMIN->value]),
                    'email' => 'admin@example.com',
                ]
            );
        }
    }
}
