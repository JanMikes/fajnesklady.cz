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
    // Email constants
    public const USER_EMAIL = 'user@example.com';
    public const UNVERIFIED_EMAIL = 'unverified@example.com';
    public const LANDLORD_EMAIL = 'landlord@example.com';
    public const LANDLORD2_EMAIL = 'landlord2@example.com';
    public const TENANT_EMAIL = 'tenant@example.com';
    public const ADMIN_EMAIL = 'admin@example.com';

    // Reference constants
    public const REF_USER = 'user-regular';
    public const REF_UNVERIFIED = 'user-unverified';
    public const REF_LANDLORD = 'user-landlord';
    public const REF_LANDLORD2 = 'user-landlord2';
    public const REF_TENANT = 'user-tenant';
    public const REF_ADMIN = 'user-admin';

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
            email: self::USER_EMAIL,
            password: '',
            firstName: 'Jan',
            lastName: 'Novak',
            createdAt: $now,
        );
        $user->changePassword($this->passwordHasher->hashPassword($user, 'password'), $now);
        $user->markAsVerified($now);
        $manager->persist($user);
        $this->addReference(self::REF_USER, $user);

        // Unverified user
        $unverified = new User(
            id: Uuid::v7(),
            email: self::UNVERIFIED_EMAIL,
            password: '',
            firstName: 'Petr',
            lastName: 'Svoboda',
            createdAt: $now,
        );
        $unverified->changePassword($this->passwordHasher->hashPassword($unverified, 'password'), $now);
        // Don't mark as verified
        $manager->persist($unverified);
        $this->addReference(self::REF_UNVERIFIED, $unverified);

        // Landlord user
        $landlord = new User(
            id: Uuid::v7(),
            email: self::LANDLORD_EMAIL,
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
        $this->addReference(self::REF_LANDLORD, $landlord);

        // Second landlord for isolation tests
        $landlord2 = new User(
            id: Uuid::v7(),
            email: self::LANDLORD2_EMAIL,
            password: '',
            firstName: 'Pavel',
            lastName: 'Skladnik',
            createdAt: $now,
        );
        $landlord2->changePassword($this->passwordHasher->hashPassword($landlord2, 'password'), $now);
        $landlord2->markAsVerified($now);
        $landlord2->changeRole(UserRole::LANDLORD, $now);
        $manager->persist($landlord2);
        $this->addReference(self::REF_LANDLORD2, $landlord2);

        // Tenant user for order tests
        $tenant = new User(
            id: Uuid::v7(),
            email: self::TENANT_EMAIL,
            password: '',
            firstName: 'Eva',
            lastName: 'Najemce',
            createdAt: $now,
        );
        $tenant->changePassword($this->passwordHasher->hashPassword($tenant, 'password'), $now);
        $tenant->markAsVerified($now);
        $manager->persist($tenant);
        $this->addReference(self::REF_TENANT, $tenant);

        // Admin user
        $admin = new User(
            id: Uuid::v7(),
            email: self::ADMIN_EMAIL,
            password: '',
            firstName: 'Admin',
            lastName: 'System',
            createdAt: $now,
        );
        $admin->changePassword($this->passwordHasher->hashPassword($admin, 'password'), $now);
        $admin->markAsVerified($now);
        $admin->changeRole(UserRole::ADMIN, $now);
        $manager->persist($admin);
        $this->addReference(self::REF_ADMIN, $admin);

        $manager->flush();
    }
}
