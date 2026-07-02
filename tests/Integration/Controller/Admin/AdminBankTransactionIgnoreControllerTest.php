<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminBankTransactionIgnoreControllerTest extends WebTestCase
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
        $tx = $this->createBankTransaction('unmatched');

        $this->client->request('POST', $this->url($tx));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForRegularUser(): void
    {
        $tx = $this->createBankTransaction('unmatched');
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('POST', $this->url($tx));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNotFoundForUnknownTransaction(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', '/portal/admin/bankovni-platby/'.Uuid::v7()->toRfc4122().'/ignorovat');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAdminIgnoresUnmatchedTransactionWithReason(): void
    {
        $tx = $this->createBankTransaction('unmatched');
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', $this->url($tx), ['reason' => 'Provozní platba']);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');

        $reloaded = $this->reload($tx);
        $this->assertTrue($reloaded->isIgnored());
        $this->assertSame('Provozní platba', $reloaded->ignoreReason);
        $this->assertNotNull($reloaded->pairedBy);
        $this->assertNotNull($reloaded->pairedAt);

        $auditLog = $this->findAuditRow($tx->id->toRfc4122(), 'ignored');
        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame('Provozní platba', $auditLog->payload['reason']);
        $this->assertSame($tx->fioTransactionId, $auditLog->payload['fio_transaction_id']);
    }

    public function testEmptyReasonIsPersistedAsNull(): void
    {
        $tx = $this->createBankTransaction('unmatched');
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', $this->url($tx), ['reason' => '   ']);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');

        $reloaded = $this->reload($tx);
        $this->assertTrue($reloaded->isIgnored());
        $this->assertNull($reloaded->ignoreReason);
    }

    public function testRedirectPreservesSourceFilter(): void
    {
        $tx = $this->createBankTransaction('unmatched');
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', $this->url($tx), ['filter' => 'unmatched']);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby?filter=unmatched');
    }

    public function testCannotIgnoreMatchedTransaction(): void
    {
        $tx = $this->createBankTransaction('matched', $this->findAnyOrder());
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', $this->url($tx), ['reason' => 'Nesouvisí']);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('[data-flash-type="error"]', 'Ignorovat lze pouze nespárované transakce.');

        $reloaded = $this->reload($tx);
        $this->assertTrue($reloaded->isMatched());
        $this->assertNull($reloaded->ignoreReason);
        $this->assertNull($this->findAuditRow($tx->id->toRfc4122(), 'ignored'));
    }

    private function url(BankTransaction $tx): string
    {
        return '/portal/admin/bankovni-platby/'.$tx->id->toRfc4122().'/ignorovat';
    }

    private function reload(BankTransaction $tx): BankTransaction
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(BankTransaction::class, $tx->id);
        \assert($reloaded instanceof BankTransaction);

        return $reloaded;
    }

    private function findAuditRow(string $entityId, string $eventType): ?AuditLog
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('entityType', 'bank_transaction')
            ->setParameter('eventType', $eventType)
            ->setParameter('entityId', $entityId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function createBankTransaction(string $status, ?Order $pairedOrder = null): BankTransaction
    {
        $tx = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'test-'.Uuid::v4()->toRfc4122(),
            amount: 150000,
            currency: 'CZK',
            variableSymbol: '1234567890',
            senderAccountNumber: '1234567890/0100',
            senderName: 'Test Sender',
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
