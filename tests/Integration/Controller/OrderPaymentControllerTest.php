<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\DataFixtures\OrderFixtures;
use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderPaymentControllerTest extends WebTestCase
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

    public function testPaymentConfirmationRedirectsToAcceptPageWithPaidStatus(): void
    {
        // Get the reserved order from fixtures
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // No login required for public payment page

        // Submit payment
        $this->client->request('POST', '/objednavka/'.$orderId.'/platba', [
            'action' => 'pay',
        ]);

        // Should redirect to accept page
        $this->assertResponseRedirects('/objednavka/'.$orderId.'/prijmout');

        // Clear entity manager to get fresh data from DB
        $this->entityManager->clear();

        // Verify order is now PAID
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertSame(OrderStatus::PAID, $updatedOrder->status, 'Order should be in PAID status after payment confirmation');

        // Follow redirect and verify no error flash is shown
        $crawler = $this->client->followRedirect();

        // Should be on accept page (not redirected to home with error)
        $this->assertStringContainsString('/objednavka/'.$orderId.'/prijmout', $this->client->getRequest()->getUri());

        // Should NOT show the error message "Tuto objednávku nelze dokončit"
        $this->assertSelectorTextNotContains('body', 'Tuto objednávku nelze dokončit');
    }

    public function testPaymentCancellationRedirectsToHomeAndCancelsOrder(): void
    {
        // Get the reserved order from fixtures
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // Submit cancellation
        $this->client->request('POST', '/objednavka/'.$orderId.'/platba', [
            'action' => 'cancel',
        ]);

        // Should redirect to home
        $this->assertResponseRedirects('/');

        // Clear entity manager to get fresh data from DB
        $this->entityManager->clear();

        // Verify order is now CANCELLED
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertSame(OrderStatus::CANCELLED, $updatedOrder->status, 'Order should be in CANCELLED status after cancellation');
    }

    public function testAcceptPageShowsContractFormForPaidOrder(): void
    {
        // Get the paid order from fixtures
        $order = $this->findOrderByStatus(OrderStatus::PAID);
        $orderId = $order->id->toRfc4122();

        // Access accept page
        $crawler = $this->client->request('GET', '/objednavka/'.$orderId.'/prijmout');

        // Should be successful
        $this->assertResponseIsSuccessful();

        // Should show contract acceptance form
        $this->assertSelectorExists('form');
        $this->assertSelectorExists('input[name="accept_contract"]');
    }

    public function testAcceptPageRedirectsForNonPaidOrder(): void
    {
        // Get the reserved order from fixtures
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // Try to access accept page without payment
        $this->client->request('GET', '/objednavka/'.$orderId.'/prijmout');

        // Should redirect to home with error
        $this->assertResponseRedirects('/');

        // Follow redirect and check for error flash
        $this->client->followRedirect();
        $this->assertSelectorTextContains('[data-flash-type="error"]', 'Tuto objednávku nelze dokončit');
    }

    public function testOrderCompletionFromAcceptPage(): void
    {
        // Get the paid order from fixtures
        $order = $this->findOrderByStatus(OrderStatus::PAID);
        $orderId = $order->id->toRfc4122();

        // Submit contract acceptance
        $this->client->request('POST', '/objednavka/'.$orderId.'/prijmout', [
            'accept_contract' => '1',
        ]);

        // Should redirect to complete page
        $this->assertResponseRedirects('/objednavka/'.$orderId.'/dokonceno');

        // Clear entity manager to get fresh data from DB
        $this->entityManager->clear();

        // Verify order is now COMPLETED
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertSame(OrderStatus::COMPLETED, $updatedOrder->status, 'Order should be in COMPLETED status after acceptance');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }

    private function findOrderByStatus(OrderStatus $status): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => $status]);
        \assert($order instanceof Order, sprintf('Order with status "%s" not found in fixtures', $status->value));

        return $order;
    }
}
