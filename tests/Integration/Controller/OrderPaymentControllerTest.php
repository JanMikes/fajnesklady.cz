<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderPaymentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private MockGoPayClient $goPayClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = static::getContainer()->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testAcceptPageShowsContractFormForReservedOrder(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        $crawler = $this->client->request('GET', '/objednavka/'.$orderId.'/prijmout');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Přijetí smluvních podmínek');
        $this->assertSelectorExists('input[name="accept_contract"]');
    }

    public function testAcceptPageRedirectsToPaymentWhenTermsAlreadyAccepted(): void
    {
        // The PAID order in fixtures has termsAcceptedAt set
        $order = $this->findOrderByStatus(OrderStatus::PAID);
        $orderId = $order->id->toRfc4122();

        $this->client->request('GET', '/objednavka/'.$orderId.'/prijmout');

        // Terms already accepted, should redirect to payment (or complete for PAID)
        $this->assertResponseRedirects('/objednavka/'.$orderId.'/platba');
    }

    public function testAcceptTermsRedirectsToPayment(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        $this->client->request('POST', '/objednavka/'.$orderId.'/prijmout', [
            'accept_contract' => '1',
        ]);

        $this->assertResponseRedirects('/objednavka/'.$orderId.'/platba');

        // Verify terms were accepted
        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertNotNull($updatedOrder->termsAcceptedAt);
    }

    public function testPaymentPageRedirectsToAcceptWhenTermsNotAccepted(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        $this->client->request('GET', '/objednavka/'.$orderId.'/platba');

        // Should redirect to accept page since terms not yet accepted
        $this->assertResponseRedirects('/objednavka/'.$orderId.'/prijmout');
    }

    public function testPaymentPageShowsOrderDetailsAfterTermsAccepted(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // Accept terms first
        $this->client->request('POST', '/objednavka/'.$orderId.'/prijmout', [
            'accept_contract' => '1',
        ]);

        // Now access payment page
        $crawler = $this->client->request('GET', '/objednavka/'.$orderId.'/platba');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Platba objednávky');
        $this->assertSelectorExists('#pay-button');
    }

    public function testPaymentInitiateReturnsGatewayUrl(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // Accept terms first
        $this->client->request('POST', '/objednavka/'.$orderId.'/prijmout', [
            'accept_contract' => '1',
        ]);

        $this->client->request('POST', '/objednavka/'.$orderId.'/platba/iniciovat', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('paymentId', $response);
        $this->assertArrayHasKey('gwUrl', $response);
        $this->assertNotEmpty($response['gwUrl']);

        // Verify order status changed
        $this->entityManager->clear();
        $updatedOrder = $this->entityManager->find(Order::class, $order->id);
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $updatedOrder->status);
    }

    public function testPaymentWebhookReturnsSuccessResponse(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // Accept terms first
        $this->client->request('POST', '/objednavka/'.$orderId.'/prijmout', [
            'accept_contract' => '1',
        ]);

        // Then initiate payment
        $this->client->request('POST', '/objednavka/'.$orderId.'/platba/iniciovat', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $paymentId = $response['paymentId'];

        // Call webhook
        $this->client->request('GET', '/webhook/gopay', ['id' => $paymentId]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('OK', $this->client->getResponse()->getContent());
    }

    public function testPaymentReturnRedirectsToCompleteOnSuccess(): void
    {
        // PAID order has terms accepted - should redirect to complete page
        $order = $this->findOrderByStatus(OrderStatus::PAID);
        $orderId = $order->id->toRfc4122();

        $this->client->request('GET', '/objednavka/'.$orderId.'/platba/navrat');

        // PAID order redirects to complete page
        $this->assertResponseRedirects('/objednavka/'.$orderId.'/dokonceno');
    }

    public function testPaymentReturnRedirectsToPaymentOnPending(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // Accept terms first
        $this->client->request('POST', '/objednavka/'.$orderId.'/prijmout', [
            'accept_contract' => '1',
        ]);

        // Initiate payment but don't complete it
        $this->client->request('POST', '/objednavka/'.$orderId.'/platba/iniciovat', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
        ]);

        // Visit return URL (payment still pending)
        $this->client->request('GET', '/objednavka/'.$orderId.'/platba/navrat');

        $this->assertResponseRedirects('/objednavka/'.$orderId.'/platba');
    }

    public function testPaymentCancellationRedirectsToHomeAndCancelsOrder(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        // Accept terms first
        $this->client->request('POST', '/objednavka/'.$orderId.'/prijmout', [
            'accept_contract' => '1',
        ]);

        // Submit cancellation from payment page
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
