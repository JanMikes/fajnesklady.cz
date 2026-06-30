<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Security;

use App\Entity\User;
use App\Service\Security\PasswordConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class PasswordConfirmationTest extends WebTestCase
{
    private PasswordConfirmation $passwordConfirmation;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->passwordConfirmation = static::getContainer()->get(PasswordConfirmation::class);
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->clock = static::getContainer()->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testValidPasswordReturnsTrue(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');

        $this->assertTrue($this->passwordConfirmation->isValid($admin, 'password'));
    }

    public function testWrongPasswordReturnsFalse(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');

        $this->assertFalse($this->passwordConfirmation->isValid($admin, 'wrong-password'));
    }

    public function testEmptyPasswordReturnsFalse(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');

        $this->assertFalse($this->passwordConfirmation->isValid($admin, ''));
    }

    public function testNullPasswordReturnsFalse(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');

        $this->assertFalse($this->passwordConfirmation->isValid($admin, null));
    }

    public function testPasswordlessUserReturnsFalse(): void
    {
        $passwordless = new User(
            id: Uuid::v7(),
            email: 'passwordless@example.com',
            password: null,
            firstName: 'No',
            lastName: 'Password',
            createdAt: $this->clock->now(),
        );

        $this->assertFalse($this->passwordConfirmation->isValid($passwordless, 'anything'));
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
