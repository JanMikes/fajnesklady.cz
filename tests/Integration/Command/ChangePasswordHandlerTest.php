<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ChangePasswordCommand;
use App\Command\ChangePasswordHandler;
use App\Entity\User;
use App\Exception\InvalidCurrentPassword;
use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class ChangePasswordHandlerTest extends KernelTestCase
{
    private ChangePasswordHandler $handler;
    private UserRepository $userRepository;
    private UserPasswordHasherInterface $passwordHasher;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(ChangePasswordHandler::class);
        $this->userRepository = $container->get(UserRepository::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testChangePassword(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'changepassword@example.com', null, 'Test', 'User', $now);
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'current_password');
        $user->changePassword($hashedPassword, $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new ChangePasswordCommand(
            userId: $user->id,
            currentPassword: 'current_password',
            newPassword: 'new_password',
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertTrue($this->passwordHasher->isPasswordValid($updatedUser, 'new_password'));
        $this->assertFalse($this->passwordHasher->isPasswordValid($updatedUser, 'current_password'));
    }

    public function testThrowsExceptionWhenUserNotFound(): void
    {
        $command = new ChangePasswordCommand(
            userId: Uuid::v7(),
            currentPassword: 'current',
            newPassword: 'new',
        );

        $this->expectException(UserNotFound::class);

        ($this->handler)($command);
    }

    public function testThrowsExceptionWhenCurrentPasswordIsInvalid(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'wrongpassword@example.com', null, 'Test', 'User', $now);
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'correct_password');
        $user->changePassword($hashedPassword, $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new ChangePasswordCommand(
            userId: $user->id,
            currentPassword: 'wrong_password',
            newPassword: 'new_password',
        );

        $this->expectException(InvalidCurrentPassword::class);

        ($this->handler)($command);
    }
}
