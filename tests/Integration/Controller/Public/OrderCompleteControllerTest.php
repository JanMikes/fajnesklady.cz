<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderCompleteControllerTest extends WebTestCase
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

    public function testAnonymousVisitorSeesDocumentsCardForLimitedOrder(): void
    {
        $order = $this->findCompletedOrder(unlimited: false);

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Vaše dokumenty');
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Všeobecné obchodní podmínky', $body);
        $this->assertStringContainsString('Poučení spotřebitele', $body);
        $this->assertStringContainsString('Formulář pro odstoupení od smlouvy', $body);
        // Limited (fixed-term) order: no recurring-payments terms row.
        $this->assertStringNotContainsString('Podmínky opakovaných plateb', $body);
        // Anchor target for the email button.
        $this->assertStringContainsString('id="dokumenty"', $body);
    }

    public function testAnonymousVisitorSeesRecurringTermsForUnlimitedOrder(): void
    {
        $order = $this->findCompletedOrder(unlimited: true);

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Podmínky opakovaných plateb', $body);
    }

    public function testNonCompletedOrderRedirectsAway(): void
    {
        $reserved = $this->findOrderByStatus(OrderStatus::RESERVED);

        $this->client->request('GET', $this->buildUrl($reserved));

        $this->assertResponseRedirects();
    }

    public function testLoggedInOwnerIsRedirectedToPortal(): void
    {
        $order = $this->findCompletedOrder(unlimited: false);
        $owner = $order->user;
        $this->client->loginUser($owner, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/portal/objednavky/'.$order->id->toRfc4122(), $location);
        $this->assertStringContainsString('#dokumenty', $location);
    }

    public function testLoggedInNonOwnerSeesPublicPage(): void
    {
        $order = $this->findCompletedOrder(unlimited: false);
        $otherUser = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        // Sanity: tenant must not own this order.
        $this->assertFalse($order->user->id->equals($otherUser->id));

        $this->client->loginUser($otherUser, 'main');
        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Vaše dokumenty');
    }

    private function buildUrl(Order $order): string
    {
        return '/objednavka/'.$order->id->toRfc4122().'/dokonceno';
    }

    private function findCompletedOrder(bool $unlimited): Order
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy(['status' => OrderStatus::COMPLETED]);
        foreach ($orders as $order) {
            if ($unlimited && null === $order->endDate) {
                return $order;
            }

            if (!$unlimited && null !== $order->endDate) {
                return $order;
            }
        }

        throw new \LogicException(sprintf('No completed %s order in fixtures', $unlimited ? 'unlimited' : 'limited'));
    }

    private function findOrderByStatus(OrderStatus $status): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => $status]);
        \assert($order instanceof Order, sprintf('No order with status %s in fixtures', $status->value));

        return $order;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User "%s" not found in fixtures', $email));

        return $user;
    }
}
