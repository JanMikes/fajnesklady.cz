<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderCancelControllerTest extends WebTestCase
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

    public function testTenantCanCancelOwnReservedOrder(): void
    {
        $tenant = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        $this->client->loginUser($tenant, 'main');

        $order = $this->findReservedOrder();

        $this->client->request('POST', '/portal/objednavky/'.$order->id->toRfc4122().'/zrusit');

        $this->assertResponseRedirects('/portal/objednavky/'.$order->id->toRfc4122());

        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertSame(OrderStatus::CANCELLED, $updatedOrder->status);
    }

    public function testTenantCannotCancelPaidOrder(): void
    {
        $tenant = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        $this->client->loginUser($tenant, 'main');

        $order = $this->findPaidOrder();

        $this->client->request('POST', '/portal/objednavky/'.$order->id->toRfc4122().'/zrusit');

        $this->assertResponseRedirects('/portal/objednavky/'.$order->id->toRfc4122());

        $this->client->followRedirect();
        $this->assertSelectorExists('[data-flash-type="error"]');

        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertSame(OrderStatus::PAID, $updatedOrder->status);
    }

    public function testOtherUserCannotCancelTenantOrder(): void
    {
        $otherUser = $this->findUserByEmail(UserFixtures::USER_EMAIL);
        $this->client->loginUser($otherUser, 'main');

        $order = $this->findReservedOrder();

        $this->client->request('POST', '/portal/objednavky/'.$order->id->toRfc4122().'/zrusit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordCanCancelReservedOrder(): void
    {
        $landlord = $this->findUserByEmail(UserFixtures::LANDLORD_EMAIL);
        $this->client->loginUser($landlord, 'main');

        $order = $this->findReservedOrder();

        $this->client->request('POST', '/portal/landlord/orders/'.$order->id->toRfc4122().'/cancel');

        $this->assertResponseRedirects('/portal/landlord/orders/'.$order->id->toRfc4122());

        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertSame(OrderStatus::CANCELLED, $updatedOrder->status);
    }

    public function testOtherLandlordCannotCancelOrder(): void
    {
        $landlord2 = $this->findUserByEmail(UserFixtures::LANDLORD2_EMAIL);
        $this->client->loginUser($landlord2, 'main');

        $order = $this->findReservedOrder();

        $this->client->request('POST', '/portal/landlord/orders/'.$order->id->toRfc4122().'/cancel');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedUserIsRedirected(): void
    {
        $order = $this->findReservedOrder();

        $this->client->request('POST', '/portal/objednavky/'.$order->id->toRfc4122().'/zrusit');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('login', $this->client->getResponse()->headers->get('Location'));
    }

    public function testGetMethodNotAllowed(): void
    {
        $tenant = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        $this->client->loginUser($tenant, 'main');

        $order = $this->findReservedOrder();

        $this->client->request('GET', '/portal/objednavky/'.$order->id->toRfc4122().'/zrusit');

        $this->assertResponseStatusCodeSame(405);
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }

    private function findReservedOrder(): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => OrderStatus::RESERVED]);
        \assert($order instanceof Order, 'Reserved order not found in fixtures');

        return $order;
    }

    private function findPaidOrder(): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => OrderStatus::PAID]);
        \assert($order instanceof Order, 'Paid order not found in fixtures');

        return $order;
    }
}
