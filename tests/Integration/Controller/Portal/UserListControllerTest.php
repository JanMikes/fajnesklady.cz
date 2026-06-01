<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserListControllerTest extends WebTestCase
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

    public function testUnfilteredListShowsDluznikBadgeForDebtor(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Pouze dlužníci');
        $debtorRow = $crawler->filter('tr:contains("tenant@example.com")');
        if ($debtorRow->count() > 0) {
            $this->assertStringContainsString('Dlužník', $debtorRow->text());
        }
    }

    public function testOverdueFilterRestrictsListToDebtors(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/users?filter=overdue');

        $this->assertResponseIsSuccessful();
        // landlord@example.com has no contract — must be hidden behind the overdue filter.
        $this->assertSelectorTextNotContains('body', 'landlord@example.com');
        // tenant@ owns the CRITICAL terminated-debt contract from fixtures.
        $this->assertSelectorTextContains('body', 'tenant@example.com');
    }

    public function testListRendersRentalAndMrrColumnsAndChips(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'S aktivními smlouvami');
        $this->assertSelectorTextContains('body', 'Bez aktivních smluv');
        $this->assertSelectorTextContains('body', 'Smlouvy');
        $this->assertSelectorTextContains('body', 'MRR');
    }

    public function testActiveFilterListsOnlyUsersWithActiveContracts(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/users?filter=active');

        $this->assertResponseIsSuccessful();
        $tableEmails = $crawler->filter('table tbody td')->extract(['_text']);
        $tableText = implode(' ', $tableEmails);
        $this->assertStringContainsString('tenant@example.com', $tableText);
        // admin@ never holds a tenant contract — must not appear in the user-list table.
        $this->assertStringNotContainsString('admin@example.com', $tableText);
    }

    public function testInactiveFilterExcludesUsersWithActiveContracts(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/users?filter=inactive');

        $this->assertResponseIsSuccessful();
        $tableText = implode(' ', $crawler->filter('table tbody td')->extract(['_text']));
        $this->assertStringNotContainsString('tenant@example.com', $tableText);
        $this->assertStringContainsString('admin@example.com', $tableText);
    }

    public function testUnknownFilterFallsBackToAll(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/users?filter=garbage');

        $this->assertResponseIsSuccessful();
        // "Vše" chip is the active (btn-primary) one when filter is null.
        $this->assertGreaterThan(0, $crawler->filter('a.btn-primary:contains("Vše")')->count());
    }

    public function testSearchNarrowsTableToMatchingUser(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/users?q=tenant@example.com');

        $this->assertResponseIsSuccessful();
        $tableText = implode(' ', $crawler->filter('table tbody td')->extract(['_text']));
        $this->assertStringContainsString('tenant@example.com', $tableText);
        $this->assertStringNotContainsString('landlord@example.com', $tableText);
    }

    public function testSearchPreservesActiveFilterChip(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/users?q=example&filter=unverified');

        $this->assertResponseIsSuccessful();
        // The hidden filter field keeps the chip active across a search submit.
        $this->assertGreaterThan(0, $crawler->filter('a.btn-accent:contains("Neověření")')->count());
    }

    public function testNoMatchShowsEmptyState(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/users?q=zzz-definitely-no-such-user');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Žádní uživatelé neodpovídají hledání.');
    }

    public function testSortableHeaderTogglesDirectionAndRendersArrow(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        // Active sort=mrr&dir=desc — the MRR header link must flip to asc and show the ▼ arrow.
        $crawler = $this->client->request('GET', '/portal/users?sort=mrr&dir=desc');

        $this->assertResponseIsSuccessful();
        $mrrHeader = $crawler->filter('th:contains("MRR")');
        $this->assertStringContainsString('▼', $mrrHeader->text());
        $toggleLink = $mrrHeader->filter('a')->attr('href');
        $this->assertStringContainsString('sort=mrr', (string) $toggleLink);
        $this->assertStringContainsString('dir=asc', (string) $toggleLink);
    }

    public function testInvalidSortFallsBackToCreatedDesc(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/users?sort=evil&dir=evil');

        $this->assertResponseIsSuccessful();
        // Default sort = created desc — the Vytvořeno header carries the active ▼ arrow.
        $createdHeader = $crawler->filter('th:contains("Vytvořeno")');
        $this->assertStringContainsString('▼', $createdHeader->text());
    }

    public function testNonAdminGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', '/portal/users');

        $this->assertResponseStatusCodeSame(403);
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
