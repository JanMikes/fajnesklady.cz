<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\StorageStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class BackfillVariableSymbolsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get('doctrine')->getManager();

        $application = new Application(static::$kernel);
        $this->commandTester = new CommandTester($application->find('app:backfill-variable-symbols'));
    }

    public function testAssignsVariableSymbolToOrderThatLacksOne(): void
    {
        $order = $this->createOrderWithoutVariableSymbol();

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        $this->entityManager->refresh($order);
        $this->assertNotNull($order->variableSymbol);
        $this->assertSame(10, \strlen($order->variableSymbol));
    }

    public function testSecondRunIsANoOp(): void
    {
        $this->createOrderWithoutVariableSymbol();

        $this->commandTester->execute([]);
        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No orders without a variable symbol.', $this->commandTester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $order = $this->createOrderWithoutVariableSymbol();

        $this->commandTester->execute(['--dry-run' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('would be assigned', $this->commandTester->getDisplay());

        $this->entityManager->refresh($order);
        $this->assertNull($order->variableSymbol);
    }

    /**
     * OrderService assigns a VS at creation, so a row without one can only be
     * built by persisting the entity directly — which is exactly the legacy
     * shape this command exists to repair.
     */
    private function createOrderWithoutVariableSymbol(): Order
    {
        $storage = $this->findAvailableStorage();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $order = new Order(
            id: Uuid::v7(),
            user: $this->findTenant(),
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+1 day'),
            endDate: $now->modify('+6 months'),
            firstPaymentPrice: 150_000,
            expiresAt: $now->modify('+30 days'),
            createdAt: $now,
        );

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->assertNull($order->variableSymbol);

        return $order;
    }

    private function findTenant(): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', UserFixtures::TENANT_EMAIL)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($user instanceof User, 'Tenant fixture user not found');

        return $user;
    }

    private function findAvailableStorage(): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->join('s.place', 'p')
            ->where('s.status = :status')
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($storage instanceof Storage, 'No available storage found in fixtures');

        return $storage;
    }
}
