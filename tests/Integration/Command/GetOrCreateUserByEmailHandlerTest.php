<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\GetOrCreateUserByEmailCommand;
use App\Command\GetOrCreateUserByEmailHandler;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class GetOrCreateUserByEmailHandlerTest extends KernelTestCase
{
    private GetOrCreateUserByEmailHandler $handler;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(GetOrCreateUserByEmailHandler::class);
        $this->userRepository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testNewUserPersistsPhoneAndBirthDate(): void
    {
        $command = new GetOrCreateUserByEmailCommand(
            email: 'newuser+'.uniqid().'@example.com',
            firstName: 'Jan',
            lastName: 'Mikeš',
            phone: '+420777111222',
            birthDate: new \DateTimeImmutable('1985-04-12'),
            billingStreet: 'Františka Formana 237/31',
            billingCity: 'Ostrava',
            billingPostalCode: '70030',
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $persisted = $this->userRepository->findByEmail($command->email);
        $this->assertNotNull($persisted);
        $this->assertSame('+420777111222', $persisted->phone);
        $this->assertNotNull($persisted->birthDate);
        $this->assertSame('1985-04-12', $persisted->birthDate->format('Y-m-d'));
    }

    public function testExistingUserGetsPhoneAndBirthDateFromReOrderForm(): void
    {
        // Regression: an existing passwordless user (created earlier by
        // GetOrCreateUserByEmailHandler when phone/birthDate either weren't
        // collected or weren't sticky) re-orders. The new order form supplies
        // phone + birthDate; these must land on the User entity so the
        // generated contract doesn't print "Nar. -" / "Telefon: -".
        $email = 'returning+'.uniqid().'@example.com';
        $existing = new User(
            id: Uuid::v7(),
            email: $email,
            password: null,
            firstName: 'Jan',
            lastName: 'Mikeš',
            createdAt: new \DateTimeImmutable('2025-06-01'),
        );
        $this->userRepository->save($existing);
        $this->entityManager->flush();

        $this->assertNull($existing->phone, 'Sanity: existing user starts without a phone.');
        $this->assertNull($existing->birthDate, 'Sanity: existing user starts without a birthDate.');

        $command = new GetOrCreateUserByEmailCommand(
            email: $email,
            firstName: 'Jan',
            lastName: 'Mikeš',
            phone: '+420777111222',
            birthDate: new \DateTimeImmutable('1985-04-12'),
            billingStreet: 'Františka Formana 237/31',
            billingCity: 'Ostrava',
            billingPostalCode: '70030',
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $refreshed = $this->userRepository->findByEmail($email);
        $this->assertNotNull($refreshed);
        $this->assertSame('+420777111222', $refreshed->phone);
        $this->assertNotNull($refreshed->birthDate);
        $this->assertSame('1985-04-12', $refreshed->birthDate->format('Y-m-d'));
    }

    public function testExistingUserKeepsStoredPhoneWhenFormFieldEmpty(): void
    {
        // An empty form field on re-order must never clobber a previously
        // stored phone. Same for birthDate (though it's required on the
        // personal-tenant form, the handler shouldn't assume that).
        $email = 'storedphone+'.uniqid().'@example.com';
        $existing = new User(
            id: Uuid::v7(),
            email: $email,
            password: null,
            firstName: 'Jan',
            lastName: 'Mikeš',
            createdAt: new \DateTimeImmutable('2025-06-01'),
        );
        $existing->updateProfile('Jan', 'Mikeš', '+420777000111', new \DateTimeImmutable('2025-06-01'));
        $existing->updateBirthDate(new \DateTimeImmutable('1985-04-12'), new \DateTimeImmutable('2025-06-01'));
        $this->userRepository->save($existing);
        $this->entityManager->flush();

        $command = new GetOrCreateUserByEmailCommand(
            email: $email,
            firstName: 'Jan',
            lastName: 'Mikeš',
            phone: null,
            birthDate: null,
            billingStreet: 'Františka Formana 237/31',
            billingCity: 'Ostrava',
            billingPostalCode: '70030',
        );

        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $refreshed = $this->userRepository->findByEmail($email);
        $this->assertNotNull($refreshed);
        $this->assertSame('+420777000111', $refreshed->phone, 'Stored phone must survive an empty form field.');
        $this->assertNotNull($refreshed->birthDate);
        $this->assertSame('1985-04-12', $refreshed->birthDate->format('Y-m-d'), 'Stored birthDate must survive an empty form field.');
    }
}
