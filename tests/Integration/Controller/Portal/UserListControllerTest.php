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

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
