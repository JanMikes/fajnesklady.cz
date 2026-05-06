<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal\User;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderDetailControllerTest extends WebTestCase
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

    public function testOwnerSeesOrderWithDocumentsCard(): void
    {
        $order = $this->findCompletedOrder();
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Detail objednávky');
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vaše dokumenty', $body);
        $this->assertStringContainsString('id="dokumenty"', $body);
    }

    public function testOtherUserCannotAccessOthersOrder(): void
    {
        $order = $this->findCompletedOrder();
        $otherUser = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        $this->assertFalse($order->user->id->equals($otherUser->id));

        $this->client->loginUser($otherUser, 'main');
        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotAccessUserOrderDetail(): void
    {
        // Admins use /portal/admin/orders/{id}; the user-portal route is owner-only.
        $order = $this->findCompletedOrder();
        $admin = $this->findUserByEmail(UserFixtures::ADMIN_EMAIL);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $order = $this->findCompletedOrder();

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseRedirects();
        $this->assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function buildUrl(Order $order): string
    {
        return '/portal/objednavky/'.$order->id->toRfc4122();
    }

    private function findCompletedOrder(): Order
    {
        // Pin to USER so non-owner tests using TENANT are deterministic.
        $user = $this->findUserByEmail(UserFixtures::USER_EMAIL);
        $orders = $this->entityManager->getRepository(Order::class)->findBy([
            'status' => OrderStatus::COMPLETED,
            'user' => $user,
        ]);
        foreach ($orders as $order) {
            if (null !== $order->endDate) {
                return $order;
            }
        }

        throw new \LogicException('No completed limited-term order owned by USER fixture.');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User "%s" not found in fixtures', $email));

        return $user;
    }
}
