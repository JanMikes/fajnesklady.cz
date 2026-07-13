<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\TerminationReason;
use App\Service\Order\OrderReferenceFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminOrderListControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/portal/admin/orders');

        $this->assertResponseRedirects('/login');
    }

    public function testLandlordGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/orders');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testReferenceColumnShowsCanonicalReference(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $order = $this->findAnyOrder();
        $reference = static::getContainer()->get(OrderReferenceFormatter::class)->format($order);

        $crawler = $this->client->request('GET', '/portal/admin/orders');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('thead', 'Číslo');
        // The canonical reference must appear and link to the order detail.
        self::assertStringContainsString($reference, $crawler->filter('table')->html());
    }

    public function testTerminatedContractShowsDebtAndOverdueBadges(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $contract = $this->findContractWithDebt();
        $reference = static::getContainer()->get(OrderReferenceFormatter::class)->format($contract->order);

        // Search by the order reference so the indebted order lands on page 1.
        $this->client->request('GET', '/portal/admin/orders?q='.$reference);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Dluh');
        $this->assertSelectorTextContains('table', 'Po splatnosti');
    }

    public function testTerminatedContractShowsUkoncenoBadgeInList(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        // Terminating a contract sets contract.terminatedAt but leaves the order
        // status = completed; the list must surface the ended state anyway.
        $contract = $this->findAnyContract();
        $contract->terminate(
            static::getContainer()->get(ClockInterface::class)->now(),
            TerminationReason::ADMIN,
            releaseStorage: false,
        );
        $this->entityManager->flush();

        $reference = static::getContainer()->get(OrderReferenceFormatter::class)->format($contract->order);
        $this->client->request('GET', '/portal/admin/orders?q='.$reference);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Ukončeno');
    }

    public function testSearchByCustomerEmailFindsOrders(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/orders?q=tenant@example.com');

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $this->client->getCrawler()->filter('tbody tr td.font-mono')->count(),
            'Expected ≥1 order row when searching tenant@example.com.',
        );
        $this->assertSelectorTextContains('table', 'tenant@example.com');
    }

    public function testSearchByCustomerNameFindsOrders(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $tenant = $this->findUserByEmail('tenant@example.com');

        $this->client->request('GET', '/portal/admin/orders?q='.urlencode($tenant->fullName));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', $tenant->fullName);
    }

    public function testSearchWithNoMatchRendersEmptyState(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/orders?q=zzz-nonexistent-zzz-99999999');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Žádné objednávky neodpovídají hledání.');
    }

    public function testSearchPreservesActiveFilter(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        // Free filter + a name search: combines with AND. The page must render
        // without error and keep the filter selected in the chips.
        $this->client->request('GET', '/portal/admin/orders?filter=free&q=tenant@example.com');

        $this->assertResponseIsSuccessful();
        // Clear link carries the active filter.
        self::assertGreaterThan(
            0,
            $this->client->getCrawler()->filter('a:contains("Zrušit")')->count(),
        );
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
        \assert($user instanceof User);

        return $user;
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

    private function findAnyContract(): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.terminatedAt IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract);

        return $contract;
    }

    private function findContractWithDebt(): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.outstandingDebtAmount > 0')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract);

        return $contract;
    }
}
