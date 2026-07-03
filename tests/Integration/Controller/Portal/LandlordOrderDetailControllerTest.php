<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\User;
use App\Enum\FineType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only "Smluvní pokuty" panel on the landlord order detail (spec 080).
 * Uses the active contract on storage C1 (Praha Centrum, owned by landlord@).
 */
class LandlordOrderDetailControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->clock = $container->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testFinesPanelIsAbsentWhenContractHasNoFines(): void
    {
        $contract = $this->findRecurringContract();

        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');
        $this->client->request('GET', '/portal/landlord/orders/'.$contract->order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Smluvní pokuty', $body);
    }

    public function testFinesPanelListsFineReadOnly(): void
    {
        $contract = $this->findRecurringContract();
        $now = $this->clock->now();

        $fine = new Fine(
            id: Uuid::v7(),
            contract: $contract,
            user: $contract->user,
            issuedBy: $this->findUserByEmail('landlord@example.com'),
            type: FineType::DIRTY_STORAGE,
            amountInHaler: 600000,
            description: 'Znečištěná skladovací jednotka.',
            issuedAt: $now,
            createdAt: $now,
        );
        // Constructor buffers a FineIssued event; drop it so persisting directly
        // in the test stays side-effect free (mirrors the fixtures' pattern).
        $fine->popEvents();
        $this->entityManager->persist($fine);
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');
        $this->client->request('GET', '/portal/landlord/orders/'.$contract->order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Smluvní pokuty', $body);
        $this->assertStringContainsString('6 000 Kč', $body);
        $this->assertStringContainsString('Znečištění skladovací jednotky', $body);
        // Read-only: no cancel action and no create button for landlords.
        $this->assertStringNotContainsString('Zrušit pokutu', $body);
        $this->assertStringNotContainsString('/portal/admin/pokuty/vytvorit/', $body);
    }

    private function findRecurringContract(): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.number = :number')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('number', 'C1')
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract, 'Recurring contract on C1 not found in fixtures');

        return $contract;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
