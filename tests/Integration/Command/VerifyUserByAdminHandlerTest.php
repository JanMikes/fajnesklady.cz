<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\VerifyUserByAdminCommand;
use App\Command\VerifyUserByAdminHandler;
use App\Entity\User;
use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class VerifyUserByAdminHandlerTest extends KernelTestCase
{
    private VerifyUserByAdminHandler $handler;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(VerifyUserByAdminHandler::class);
        $this->userRepository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testVerifyUser(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'verifybyadmin@example.com', 'password123', 'Test', 'User', $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $this->assertFalse($user->isVerified());

        $command = new VerifyUserByAdminCommand(userId: $user->id);

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $verifiedUser = $this->userRepository->get($user->id);
        $this->assertTrue($verifiedUser->isVerified());
    }

    public function testVerifyAlreadyVerifiedUser(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'alreadyverified@example.com', 'password123', 'Test', 'User', $now);
        $user->markAsVerified($now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $this->assertTrue($user->isVerified());

        $command = new VerifyUserByAdminCommand(userId: $user->id);

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $verifiedUser = $this->userRepository->get($user->id);
        $this->assertTrue($verifiedUser->isVerified());
    }

    public function testThrowsExceptionWhenUserNotFound(): void
    {
        $command = new VerifyUserByAdminCommand(userId: Uuid::v7());

        $this->expectException(UserNotFound::class);

        ($this->handler)($command);
    }
}
