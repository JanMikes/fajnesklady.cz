<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\BankAccountMapping;
use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\BillingMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Manual bank-transfer pairing (spec 091 requirements 4 + 5).
 *
 * `paired_by` had never been populated by any production code path before this
 * controller — every auto-match passes `null` — so the tests assert it
 * explicitly rather than trusting the pairing to have happened at all.
 */
class AdminBankTransactionPairControllerTest extends WebTestCase
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
        $tx = $this->createBankTransaction();

        $this->client->request('GET', $this->url($tx));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForRegularUser(): void
    {
        $tx = $this->createBankTransaction();
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', $this->url($tx));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeniedForLandlord(): void
    {
        $tx = $this->createBankTransaction();
        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');

        $this->client->request('GET', $this->url($tx));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNotFoundForUnknownTransaction(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/portal/admin/bankovni-platby/'.Uuid::v7()->toRfc4122().'/sparovat');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAdminSeesOrderPicker(): void
    {
        $tx = $this->createBankTransaction();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', $this->url($tx));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Spárovat bankovní platbu');
        $this->assertGreaterThan(0, $crawler->filter('input[name="q"].form-input')->count(), 'The picker needs a search box.');
        $this->assertGreaterThan(0, $crawler->filter('table tbody tr')->count(), 'The empty search lists recent orders.');
    }

    public function testPickerSearchesByVariableSymbol(): void
    {
        $order = $this->findOrderByStorageNumber('X2');
        $variableSymbol = $order->variableSymbol;
        $this->assertNotNull($variableSymbol, 'The upfront fixture order carries a variable symbol.');

        $tx = $this->createBankTransaction();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', $this->url($tx).'?q='.$variableSymbol);

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $crawler->filter('table tbody tr')->count());
        $this->assertSelectorTextContains('table tbody', $variableSymbol);
    }

    public function testAdminSeesAllocationConfirmation(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $tx = $this->createBankTransaction();
        $this->loginAdmin();

        $this->client->request('GET', $this->url($tx).'?order='.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[method="post"] button[type="submit"]');
        $this->assertSelectorTextContains('body', 'Co platba uhradí');
    }

    public function testAdminPairsTransactionAndPairedByIsRecorded(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $admin = $this->findUserByEmail('admin@example.com');
        $tx = $this->createBankTransaction();
        $this->client->loginUser($admin, 'main');

        $this->client->request('POST', $this->url($tx), ['order' => $order->id->toRfc4122()]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');

        $reloaded = $this->reload($tx);
        $this->assertTrue($reloaded->isMatched());
        $this->assertSame('manual', $reloaded->matchMethod);
        $this->assertNotNull($reloaded->pairedOrder);
        $this->assertTrue($reloaded->pairedOrder->id->equals($order->id));
        $this->assertNotNull($reloaded->pairedBy, 'paired_by must finally be populated — this is the first path that writes it.');
        $this->assertTrue($reloaded->pairedBy->id->equals($admin->id));
        $this->assertNotNull($reloaded->pairedAt);

        $auditLog = $this->findAuditRow($tx->id->toRfc4122(), 'manually_paired');
        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame($order->id->toRfc4122(), $auditLog->payload['order_id']);
        $this->assertSame($admin->id->toRfc4122(), $auditLog->payload['admin_id']);
    }

    public function testPairingPreservesSourceFilterOnRedirect(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $tx = $this->createBankTransaction();
        $this->loginAdmin();

        $this->client->request('POST', $this->url($tx), [
            'order' => $order->id->toRfc4122(),
            'filter' => 'unmatched',
        ]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby?filter=unmatched');
    }

    public function testNoteIsStoredInTheAuditTrail(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $tx = $this->createBankTransaction();
        $this->loginAdmin();

        $this->client->request('POST', $this->url($tx), [
            'order' => $order->id->toRfc4122(),
            'note' => 'Zákazník uvedl chybný variabilní symbol',
        ]);

        $auditLog = $this->findAuditRow($tx->id->toRfc4122(), 'manually_paired');
        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame('Zákazník uvedl chybný variabilní symbol', $auditLog->payload['note']);
    }

    public function testCannotPairAlreadyMatchedTransaction(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $tx = $this->createBankTransaction();
        $tx->pairToOrder($order, 'variable_symbol', null, $this->clock->now());
        $this->entityManager->flush();

        $this->loginAdmin();

        $this->client->request('POST', $this->url($tx), ['order' => $order->id->toRfc4122()]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('[data-flash-type="error"]', 'Spárovat lze pouze nespárované nebo částečně uhrazené transakce.');

        $reloaded = $this->reload($tx);
        $this->assertSame('variable_symbol', $reloaded->matchMethod);
        $this->assertNull($reloaded->pairedBy);
        $this->assertNull($this->findAuditRow($tx->id->toRfc4122(), 'manually_paired'));
    }

    public function testPairingAmountMismatchRowToDifferentOrderClearsThePreviousPairing(): void
    {
        $wrongOrder = $this->findOrderByStorageNumber('X2');
        $correctOrder = $this->findOrderByStorageNumber('B3');

        $tx = $this->createBankTransaction();
        $tx->markAmountMismatch($wrongOrder, 'variable_symbol', 310000, $this->clock->now());
        $this->entityManager->flush();

        $this->loginAdmin();

        $this->client->request('POST', $this->url($tx), ['order' => $correctOrder->id->toRfc4122()]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');

        $reloaded = $this->reload($tx);
        $this->assertTrue($reloaded->isMatched());
        $this->assertNotNull($reloaded->pairedOrder);
        $this->assertTrue($reloaded->pairedOrder->id->equals($correctOrder->id));
        $this->assertNull($reloaded->expectedAmountInHaler, 'The stale expected amount belonged to the order we just unpaired from.');
        $this->assertSame('manual', $reloaded->matchMethod);
    }

    /**
     * Spec 091 D1 — the recurring mandate only exists if the FIRST charge went
     * through the card gateway, so no admin may settle it by wire.
     */
    public function testCardOrderFirstPaymentCannotBePaired(): void
    {
        $cardOrder = $this->findOrderByStorageNumber('B1');
        $this->assertSame(BillingMode::AUTO_RECURRING, $cardOrder->billingMode);
        $this->assertTrue($cardOrder->canBePaid());

        $tx = $this->createBankTransaction();
        $this->loginAdmin();

        $this->client->request('POST', $this->url($tx), ['order' => $cardOrder->id->toRfc4122()]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('[data-flash-type="error"]', 'První platbu karetní objednávky nelze uhradit převodem.');

        $reloaded = $this->reload($tx);
        $this->assertTrue($reloaded->isUnmatched());
        $this->assertNull($reloaded->pairedBy);
        $this->assertNull($this->findAuditRow($tx->id->toRfc4122(), 'manually_paired'));
    }

    public function testConfirmationExplainsWhyACardOrderCannotBePaired(): void
    {
        $cardOrder = $this->findOrderByStorageNumber('B1');
        $tx = $this->createBankTransaction();
        $this->loginAdmin();

        $this->client->request('GET', $this->url($tx).'?order='.$cardOrder->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-error', 'Tuto platbu nelze s objednávkou spárovat.');
        $this->assertSelectorNotExists('form[method="post"] button[type="submit"]');
    }

    public function testRememberSenderAccountCreatesExactlyOneMapping(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $account = '9876543210/0300';

        $tx = $this->createBankTransaction($account);
        $this->loginAdmin();

        $this->client->request('POST', $this->url($tx), [
            'order' => $order->id->toRfc4122(),
            'rememberSenderAccount' => '1',
        ]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');
        $this->assertTrue($this->reload($tx)->isMatched());
        $this->assertSame(1, $this->countMappings($account, $order));
    }

    /**
     * A repeat payer: the same account already maps to the same order. The
     * (accountNumber, order) unique constraint must not turn a routine second
     * pairing into a 500.
     */
    public function testRememberingAnAlreadyKnownSenderAccountDoesNotViolateTheUniqueConstraint(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $admin = $this->findUserByEmail('admin@example.com');
        $account = '4444333322/2010';

        $this->entityManager->persist(new BankAccountMapping(
            id: Uuid::v7(),
            accountNumber: $account,
            user: $order->user,
            order: $order,
            createdBy: $admin,
            createdAt: $this->clock->now(),
        ));
        $this->entityManager->flush();

        $tx = $this->createBankTransaction($account);
        $this->client->loginUser($admin, 'main');

        $this->client->request('POST', $this->url($tx), [
            'order' => $order->id->toRfc4122(),
            'rememberSenderAccount' => '1',
        ]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');
        $this->assertTrue($this->reload($tx)->isMatched());
        $this->assertSame(1, $this->countMappings($account, $order));
    }

    public function testUncheckedRememberSenderAccountCreatesNoMapping(): void
    {
        $order = $this->findOrderByStorageNumber('B3');
        $account = '5555000011/0800';

        $tx = $this->createBankTransaction($account);
        $this->loginAdmin();

        $this->client->request('POST', $this->url($tx), ['order' => $order->id->toRfc4122()]);

        $this->assertResponseRedirects('/portal/admin/bankovni-platby');
        $this->assertSame(0, $this->countMappings($account, $order));
    }

    private function url(BankTransaction $tx): string
    {
        return '/portal/admin/bankovni-platby/'.$tx->id->toRfc4122().'/sparovat';
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
    }

    private function reload(BankTransaction $tx): BankTransaction
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(BankTransaction::class, $tx->id);
        \assert($reloaded instanceof BankTransaction);

        return $reloaded;
    }

    private function countMappings(string $accountNumber, Order $order): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(BankAccountMapping::class, 'm')
            ->where('m.accountNumber = :accountNumber')
            ->andWhere('m.order = :order')
            ->setParameter('accountNumber', $accountNumber)
            ->setParameter('order', $order)
            ->getQuery()
            ->getSingleScalarResult();
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

    private function createBankTransaction(string $senderAccountNumber = '1234567890/0100'): BankTransaction
    {
        $tx = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'test-'.Uuid::v4()->toRfc4122(),
            amount: 150000,
            currency: 'CZK',
            variableSymbol: null,
            senderAccountNumber: $senderAccountNumber,
            senderName: 'Test Sender',
            transactionDate: $this->clock->now(),
            comment: null,
            createdAt: $this->clock->now(),
        );

        $this->entityManager->persist($tx);
        $this->entityManager->flush();

        return $tx;
    }

    private function findOrderByStorageNumber(string $number): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order, sprintf('No fixture order found for storage "%s"', $number));

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
