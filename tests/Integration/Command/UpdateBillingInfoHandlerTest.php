<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\UpdateBillingInfoCommand;
use App\Command\UpdateBillingInfoHandler;
use App\Entity\User;
use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class UpdateBillingInfoHandlerTest extends KernelTestCase
{
    private UpdateBillingInfoHandler $handler;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(UpdateBillingInfoHandler::class);
        $this->userRepository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testUpdateBillingInfo(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'updatebilling@example.com', 'password123', 'Test', 'User', $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new UpdateBillingInfoCommand(
            userId: $user->id,
            companyName: 'Test Company s.r.o.',
            companyId: '12345678',
            companyVatId: 'CZ12345678',
            billingStreet: 'Hlavní 123',
            billingCity: 'Praha',
            billingPostalCode: '110 00',
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertSame('Test Company s.r.o.', $updatedUser->companyName);
        $this->assertSame('12345678', $updatedUser->companyId);
        $this->assertSame('CZ12345678', $updatedUser->companyVatId);
        $this->assertSame('Hlavní 123', $updatedUser->billingStreet);
        $this->assertSame('Praha', $updatedUser->billingCity);
        $this->assertSame('110 00', $updatedUser->billingPostalCode);
        $this->assertTrue($updatedUser->hasBillingInfo());
    }

    public function testUpdateBillingInfoWithNullVatId(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'billingnovat@example.com', 'password123', 'Test', 'User', $now);
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new UpdateBillingInfoCommand(
            userId: $user->id,
            companyName: 'Small Company',
            companyId: '87654321',
            companyVatId: null,
            billingStreet: 'Vedlejší 456',
            billingCity: 'Brno',
            billingPostalCode: '602 00',
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertSame('Small Company', $updatedUser->companyName);
        $this->assertSame('87654321', $updatedUser->companyId);
        $this->assertNull($updatedUser->companyVatId);
        $this->assertSame('Vedlejší 456', $updatedUser->billingStreet);
        $this->assertSame('Brno', $updatedUser->billingCity);
        $this->assertSame('602 00', $updatedUser->billingPostalCode);
        $this->assertTrue($updatedUser->hasBillingInfo());
    }

    public function testThrowsExceptionWhenUserNotFound(): void
    {
        $command = new UpdateBillingInfoCommand(
            userId: Uuid::v7(),
            companyName: 'Test',
            companyId: '12345678',
            companyVatId: null,
            billingStreet: 'Street',
            billingCity: 'City',
            billingPostalCode: '12345',
        );

        $this->expectException(UserNotFound::class);

        ($this->handler)($command);
    }

    public function testClearBillingInfo(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'clearbilling@example.com', 'password123', 'Test', 'User', $now);
        $user->updateBillingInfo(
            'Old Company',
            '12345678',
            'CZ12345678',
            'Old Street',
            'Old City',
            '11000',
            $now,
        );
        $this->userRepository->save($user);
        $this->entityManager->flush();

        $command = new UpdateBillingInfoCommand(
            userId: $user->id,
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: null,
            billingCity: null,
            billingPostalCode: null,
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updatedUser = $this->userRepository->get($user->id);
        $this->assertNull($updatedUser->companyName);
        $this->assertNull($updatedUser->companyId);
        $this->assertNull($updatedUser->companyVatId);
        $this->assertNull($updatedUser->billingStreet);
        $this->assertNull($updatedUser->billingCity);
        $this->assertNull($updatedUser->billingPostalCode);
        $this->assertFalse($updatedUser->hasBillingInfo());
    }
}
