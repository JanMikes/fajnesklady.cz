<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal\User;

use App\DataFixtures\UserFixtures;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserViewControllerTest extends WebTestCase
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

    public function testAdminSeesFinancialOverviewAndOrdersTableForCustomerWithOrders(): void
    {
        // USER fixture owns two completed orders (B3 limited + C1 unlimited) and
        // two active contracts.
        $admin = $this->findUserByEmail(UserFixtures::ADMIN_EMAIL);
        $customer = $this->findUserByEmail(UserFixtures::USER_EMAIL);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/portal/users/'.$customer->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Finanční přehled', $body);
        $this->assertStringContainsString('Aktivní smlouvy', $body);
        $this->assertStringContainsString('Objednávky', $body);
        // Storage type + number rendered in the "Co / kde" column.
        $this->assertStringContainsString('(B3)', $body);
        // Order reference links to the admin order detail.
        $this->assertStringContainsString('/portal/admin/orders/', $body);
        $this->assertStringNotContainsString('Zákazník nemá žádné objednávky.', $body);
    }

    public function testCustomerWithoutOrdersShowsEmptyState(): void
    {
        $admin = $this->findUserByEmail(UserFixtures::ADMIN_EMAIL);
        // DEACTIVATED fixture user has no orders.
        $customer = $this->findUserByEmail(UserFixtures::DEACTIVATED_EMAIL);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/portal/users/'.$customer->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Finanční přehled', $body);
        $this->assertStringContainsString('Zákazník nemá žádné objednávky.', $body);
        // Zeroed overview, no debt highlight.
        $this->assertStringContainsString('0 Kč', $body);
    }

    public function testNonAdminCannotAccessUserDetail(): void
    {
        $tenant = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        $customer = $this->findUserByEmail(UserFixtures::USER_EMAIL);

        $this->client->loginUser($tenant, 'main');
        $this->client->request('GET', '/portal/users/'.$customer->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User "%s" not found in fixtures', $email));

        return $user;
    }
}
