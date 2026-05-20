<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminOperationsControllerTest extends WebTestCase
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

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/admin/operace');

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForRegularUser(): void
    {
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/operace');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeniedForLandlord(): void
    {
        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/operace');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAccessibleByAdminAndRendersAllSections(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/operace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Operace');

        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Předávací protokoly', $body);
        $this->assertStringContainsString('Smlouvy končící bez protokolu', $body);
        $this->assertStringContainsString('Onboarding podepsaný bez platby', $body);
        $this->assertStringContainsString('Externí předplatné brzy končící', $body);
    }

    public function testHandoverRowsLinkToAdminHandoverView(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/operace');

        $this->assertResponseIsSuccessful();
        // Fixtures seed 3 pending handover protocols → at least one admin_handover_view link must appear.
        $crawler = $this->client->getCrawler();
        $links = $crawler->filter('a[href*="/portal/admin/predavaci-protokol/"]');
        self::assertGreaterThan(0, $links->count(), 'Expected at least one admin_handover_view link in the operations hub.');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
