<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Access control for the admin onboarding page (which renders the
 * AdminOnboardingForm Live Component + its availability map, spec 071). The page
 * is ROLE_ADMIN only; everyone else is rejected.
 */
final class AdminOnboardingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
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
        $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserIsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordIsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanLoad(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseIsSuccessful();
        // The map itself only renders after a place + type are picked; the live
        // component root is always present.
        self::assertSelectorExists('[data-controller~="admin-onboarding-bridge"]');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
