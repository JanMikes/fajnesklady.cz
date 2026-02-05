<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\RequestPasswordResetCommand;
use App\Command\RequestPasswordResetHandler;
use App\DataFixtures\UserFixtures;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RequestPasswordResetHandlerTest extends KernelTestCase
{
    private RequestPasswordResetHandler $handler;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(RequestPasswordResetHandler::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testCreatesResetTokenForExistingUser(): void
    {
        $command = new RequestPasswordResetCommand(email: UserFixtures::USER_EMAIL);

        ($this->handler)($command);
        $this->entityManager->flush();

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => UserFixtures::USER_EMAIL]);
        \assert($user instanceof User);

        $resetRequest = $this->entityManager->getRepository(ResetPasswordRequest::class)
            ->findOneBy(['user' => $user]);

        $this->assertNotNull($resetRequest, 'ResetPasswordRequest should be created for existing user');
    }

    public function testDoesNotThrowForNonExistentEmail(): void
    {
        $command = new RequestPasswordResetCommand(email: 'nonexistent@example.com');

        ($this->handler)($command);

        $this->addToAssertionCount(1);
    }
}
