<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\User;
use App\Query\GetDashboardStats;
use App\Query\GetDashboardStatsQuery;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class GetDashboardStatsQueryTest extends KernelTestCase
{
    private GetDashboardStatsQuery $handler;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(GetDashboardStatsQuery::class);
        $this->userRepository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    private function createUser(string $email, bool $verified = false): User
    {
        $user = new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());

        if ($verified) {
            $user->markAsVerified(new \DateTimeImmutable());
        }

        $this->userRepository->save($user);

        return $user;
    }

    private function setUserRoles(User $user, array $roles): void
    {
        $reflection = new \ReflectionClass($user);
        $rolesProperty = $reflection->getProperty('roles');
        $rolesProperty->setValue($user, $roles);
    }

    public function testReturnsCorrectStats(): void
    {
        // Get initial counts (from fixtures)
        $initialResult = ($this->handler)(new GetDashboardStats());
        $initialTotal = $initialResult->totalUsers;
        $initialVerified = $initialResult->verifiedUsers;
        $initialAdmin = $initialResult->adminUsers;

        // Create test users
        $verifiedUser = $this->createUser('stats-verified@test.com', true);
        $unverifiedUser = $this->createUser('stats-unverified@test.com', false);
        $adminUser = $this->createUser('stats-admin@test.com', true);
        $this->setUserRoles($adminUser, ['ROLE_USER', 'ROLE_ADMIN']);

        $this->entityManager->flush();

        $result = ($this->handler)(new GetDashboardStats());

        $this->assertSame($initialTotal + 3, $result->totalUsers);
        $this->assertSame($initialVerified + 2, $result->verifiedUsers);
        $this->assertSame($initialAdmin + 1, $result->adminUsers);
        $this->assertSame($result->totalUsers - $result->verifiedUsers, $result->unverifiedUsers);
    }

    public function testCalculatesUnverifiedCorrectly(): void
    {
        $result = ($this->handler)(new GetDashboardStats());

        // unverifiedUsers should equal totalUsers - verifiedUsers
        $this->assertSame($result->totalUsers - $result->verifiedUsers, $result->unverifiedUsers);
    }
}
