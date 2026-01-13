<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\AdminUpdateUserCommand;
use App\Command\AdminUpdateUserHandler;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class AdminUpdateUserHandlerTest extends KernelTestCase
{
    private AdminUpdateUserHandler $handler;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(AdminUpdateUserHandler::class);
        $this->userRepository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testAdminUpdateUser(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'adminupdate@example.com', 'password123', 'Old', 'Name', $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new AdminUpdateUserCommand(
            userId: $user->id,
            firstName: 'New',
            lastName: 'Person',
            phone: '+420123456789',
            companyName: 'Test Company',
            companyId: '12345678',
            companyVatId: 'CZ12345678',
            billingStreet: 'Test Street 123',
            billingCity: 'Prague',
            billingPostalCode: '11000',
            role: UserRole::LANDLORD,
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertSame('New', $updatedUser->firstName);
        $this->assertSame('Person', $updatedUser->lastName);
        $this->assertSame('+420123456789', $updatedUser->phone);
        $this->assertSame('Test Company', $updatedUser->companyName);
        $this->assertSame('12345678', $updatedUser->companyId);
        $this->assertSame('CZ12345678', $updatedUser->companyVatId);
        $this->assertSame('Test Street 123', $updatedUser->billingStreet);
        $this->assertSame('Prague', $updatedUser->billingCity);
        $this->assertSame('11000', $updatedUser->billingPostalCode);
        $this->assertContains(UserRole::LANDLORD->value, $updatedUser->getRoles());
    }

    public function testAdminUpdateUserWithNullBillingInfo(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'adminupdatenull@example.com', 'password123', 'Old', 'Name', $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new AdminUpdateUserCommand(
            userId: $user->id,
            firstName: 'Updated',
            lastName: 'User',
            phone: null,
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: null,
            billingCity: null,
            billingPostalCode: null,
            role: UserRole::USER,
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertSame('Updated', $updatedUser->firstName);
        $this->assertSame('User', $updatedUser->lastName);
        $this->assertNull($updatedUser->phone);
        $this->assertNull($updatedUser->companyName);
        $this->assertNull($updatedUser->companyId);
        $this->assertNull($updatedUser->companyVatId);
        $this->assertNull($updatedUser->billingStreet);
        $this->assertNull($updatedUser->billingCity);
        $this->assertNull($updatedUser->billingPostalCode);
    }

    public function testThrowsExceptionWhenUserNotFound(): void
    {
        $command = new AdminUpdateUserCommand(
            userId: Uuid::v7(),
            firstName: 'Test',
            lastName: 'User',
            phone: null,
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: null,
            billingCity: null,
            billingPostalCode: null,
            role: UserRole::USER,
        );

        $this->expectException(UserNotFound::class);

        ($this->handler)($command);
    }
}
