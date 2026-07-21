<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminBankPaymentsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->clock = static::getContainer()->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/admin/bankovni-platby');

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForRegularUser(): void
    {
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/bankovni-platby');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeniedForLandlord(): void
    {
        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/bankovni-platby');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAccessibleByAdmin(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/bankovni-platby');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bankovní platby');
    }

    public function testRendersPairedOrderLink(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $order = $this->findAnyOrder();
        $this->createBankTransaction('matched', $order);

        $this->client->request('GET', '/portal/admin/bankovni-platby');

        $this->assertResponseIsSuccessful();
        $shortId = strtoupper(substr($order->id->toRfc4122(), 0, 8));
        $this->assertSelectorTextContains('body', $shortId);
    }

    public function testFilterUnmatched(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->createBankTransaction('unmatched');

        $this->client->request('GET', '/portal/admin/bankovni-platby?filter=unmatched');

        $this->assertResponseIsSuccessful();
    }

    public function testDefaultListDoesNotShowIgnoredTransaction(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->createIgnoredBankTransaction($admin, 'Ignorovaný odesílatel s.r.o.');

        $this->client->request('GET', '/portal/admin/bankovni-platby');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ignorovaný odesílatel s.r.o.', (string) $this->client->getResponse()->getContent());
    }

    public function testIgnoredFilterShowsIgnoredTransactionWithNote(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->createIgnoredBankTransaction($admin, 'Ignorovaný odesílatel s.r.o.');

        $this->client->request('GET', '/portal/admin/bankovni-platby?filter=ignored');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Ignorovaný odesílatel s.r.o.');
        // Badge text now comes from BankTransactionStatus::label() (spec 091).
        $this->assertSelectorTextContains('body', 'Nesouvisející');
        $this->assertSelectorTextContains('body', 'Provozní platba');
    }

    private function createIgnoredBankTransaction(User $admin, string $senderName): BankTransaction
    {
        $tx = $this->createBankTransaction('unmatched', senderName: $senderName);
        $tx->markIgnored($admin, 'Provozní platba', $this->clock->now());
        $this->entityManager->flush();

        return $tx;
    }

    private function createBankTransaction(string $status, ?Order $pairedOrder = null, string $senderName = 'Test Sender'): BankTransaction
    {
        $tx = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'test-'.Uuid::v4()->toRfc4122(),
            amount: 150000,
            currency: 'CZK',
            variableSymbol: '1234567890',
            senderAccountNumber: '1234567890/0100',
            senderName: $senderName,
            transactionDate: $this->clock->now(),
            comment: null,
            createdAt: $this->clock->now(),
        );

        if ('matched' === $status && null !== $pairedOrder) {
            $tx->pairToOrder($pairedOrder, 'vs_match', null, $this->clock->now());
        }

        $this->entityManager->persist($tx);
        $this->entityManager->flush();

        return $tx;
    }

    private function findAnyOrder(): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order);

        return $order;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
