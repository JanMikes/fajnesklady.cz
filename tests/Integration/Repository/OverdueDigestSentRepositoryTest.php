<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DataFixtures\UserFixtures;
use App\Entity\OverdueDigestSent;
use App\Entity\User;
use App\Repository\OverdueDigestSentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class OverdueDigestSentRepositoryTest extends KernelTestCase
{
    private OverdueDigestSentRepository $repository;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(OverdueDigestSentRepository::class);
        $this->entityManager = $container->get('doctrine')->getManager();

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;
    }

    public function testWasSentForAdminOnReturnsFalseInitially(): void
    {
        $admin = $this->getAdmin();
        $today = $this->clock->now()->setTime(0, 0, 0);

        $this->assertFalse($this->repository->wasSentForAdminOn($admin, $today));
    }

    public function testSavePersistsAndWasSentBecomesTrue(): void
    {
        $admin = $this->getAdmin();
        $today = $this->clock->now()->setTime(0, 0, 0);

        $this->repository->save(new OverdueDigestSent(
            id: Uuid::v7(),
            admin: $admin,
            date: $today,
            sentAt: $this->clock->now(),
            overdueCount: 3,
            totalAmount: 12_345,
        ));
        $this->entityManager->flush();

        $this->assertTrue($this->repository->wasSentForAdminOn($admin, $today));
    }

    public function testUniqueConstraintRejectsDuplicateAdminAndDate(): void
    {
        $admin = $this->getAdmin();
        $today = $this->clock->now()->setTime(0, 0, 0);

        $this->repository->save(new OverdueDigestSent(
            id: Uuid::v7(),
            admin: $admin,
            date: $today,
            sentAt: $this->clock->now(),
            overdueCount: 1,
            totalAmount: 1000,
        ));
        $this->entityManager->flush();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save(new OverdueDigestSent(
            id: Uuid::v7(),
            admin: $admin,
            date: $today,
            sentAt: $this->clock->now(),
            overdueCount: 2,
            totalAmount: 2000,
        ));
        $this->entityManager->flush();
    }

    private function getAdmin(): User
    {
        $admin = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', UserFixtures::ADMIN_EMAIL)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($admin instanceof User);

        return $admin;
    }
}
