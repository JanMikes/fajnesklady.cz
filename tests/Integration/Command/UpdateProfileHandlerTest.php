<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\UpdateProfileCommand;
use App\Command\UpdateProfileHandler;
use App\Entity\User;
use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class UpdateProfileHandlerTest extends KernelTestCase
{
    private UpdateProfileHandler $handler;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(UpdateProfileHandler::class);
        $this->userRepository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testUpdateProfile(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'updateprofile@example.com', 'password123', 'Old', 'Name', $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new UpdateProfileCommand(
            userId: $user->id,
            firstName: 'New',
            lastName: 'Person',
            phone: '+420123456789',
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertSame('New', $updatedUser->firstName);
        $this->assertSame('Person', $updatedUser->lastName);
        $this->assertSame('+420123456789', $updatedUser->phone);
    }

    public function testUpdateProfileWithNullPhone(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'updateprofilenullphone@example.com', 'password123', 'Old', 'Name', $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new UpdateProfileCommand(
            userId: $user->id,
            firstName: 'Updated',
            lastName: 'User',
            phone: null,
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertSame('Updated', $updatedUser->firstName);
        $this->assertSame('User', $updatedUser->lastName);
        $this->assertNull($updatedUser->phone);
    }

    public function testThrowsExceptionWhenUserNotFound(): void
    {
        $command = new UpdateProfileCommand(
            userId: Uuid::v7(),
            firstName: 'Test',
            lastName: 'User',
            phone: null,
        );

        $this->expectException(UserNotFound::class);

        ($this->handler)($command);
    }
}
