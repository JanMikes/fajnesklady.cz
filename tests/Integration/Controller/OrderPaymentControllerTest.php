<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Entity\Storage;
use App\Enum\OrderStatus;
use App\Enum\StorageStatus;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Uid\Uuid;

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

    public function testAcceptPageShowsContractForm(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('GET', $this->buildAcceptUrl($storage));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Rekapitulace a přijetí smlouvy');
        $this->assertSelectorExists('input[name="accept_contract"]');
        $this->assertSelectorExists('input[name="accept_vop"]');
        $this->assertSelectorExists('input[name="accept_consumer_notice"]');
        $this->assertSelectorExists('input[name="accept_gdpr"]');
        $this->assertSelectorExists('input[name="signature_data"]');
        $this->assertSelectorExists('input[name="signing_method"]');
        $this->assertSelectorExists('input[name="signature_consent"]');
    }

    public function testAcceptTermsWithSignatureRedirectsToPayment(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertMatchesRegularExpression('#/objednavka/[0-9a-f-]{36}/platba$#', $location);

        // Extract order ID and verify state
        preg_match('#/objednavka/([0-9a-f-]{36})/platba$#', $location, $matches);
        $newOrderId = Uuid::fromString($matches[1]);

        $this->entityManager->clear();
        $newOrder = $this->entityManager->find(Order::class, $newOrderId);
        $this->assertNotNull($newOrder);
        $this->assertNotNull($newOrder->termsAcceptedAt);
        $this->assertTrue($newOrder->hasSignature());
        $this->assertNotNull($newOrder->signaturePath);
        $this->assertNotNull($newOrder->signedAt);
    }

    public function testAcceptWithoutSignatureShowsError(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_data' => '',
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $this->assertResponseIsSuccessful(); // Re-renders form with error
    }

    public function testAcceptWithoutConsentShowsError(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            // missing signature_consent
        ]);

        $this->assertResponseIsSuccessful(); // Re-renders form with error
    }

    public function testAcceptWithoutContractCheckboxShowsError(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            // missing accept_contract
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $this->assertResponseIsSuccessful(); // Re-renders form with error
    }

    public function testAcceptWithoutVopCheckboxShowsError(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            // missing accept_vop
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $this->assertResponseIsSuccessful(); // Re-renders form with error
    }

    public function testAcceptWithoutConsumerNoticeCheckboxShowsError(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            // missing accept_consumer_notice
            'accept_gdpr' => '1',
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $this->assertResponseIsSuccessful(); // Re-renders form with error
    }

    public function testAcceptWithoutGdprCheckboxShowsError(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            // missing accept_gdpr
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $this->assertResponseIsSuccessful(); // Re-renders form with error
    }

    public function testAcceptWithoutRecurringPaymentsCheckboxShowsErrorForUnlimitedRental(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData(unlimited: true);

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            // missing accept_recurring_payments
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $this->assertResponseIsSuccessful(); // Re-renders form with error
    }

    public function testAcceptRecurringPaymentsNotRequiredForLimitedRental(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData(); // limited rental

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            // no accept_recurring_payments - not required for limited rental
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'draw',
            'signature_consent' => '1',
        ]);

        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect()); // Should succeed
    }

    public function testAcceptWithTypedSignature(): void
    {
        $storage = $this->findAvailableStorage();
        $this->setOrderSessionData();

        $this->client->request('POST', $this->buildAcceptUrl($storage), [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_data' => $this->createValidPngDataUrl(),
            'signing_method' => 'typed',
            'typed_name' => 'Jan Novák',
            'style_id' => 'dancing-script',
            'signature_consent' => '1',
        ]);

        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertMatchesRegularExpression('#/objednavka/[0-9a-f-]{36}/platba$#', $location);

        preg_match('#/objednavka/([0-9a-f-]{36})/platba$#', $location, $matches);
        $newOrderId = Uuid::fromString($matches[1]);

        $this->entityManager->clear();
        $newOrder = $this->entityManager->find(Order::class, $newOrderId);
        $this->assertSame('Jan Novák', $newOrder->signatureTypedName);
        $this->assertSame('dancing-script', $newOrder->signatureStyleId);
    }

    public function testPaymentPageRedirectsToOrderCreateWhenTermsNotAccepted(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();
        $storage = $order->storage;

        $this->client->request('GET', '/objednavka/'.$orderId.'/platba');

        $expectedUrl = sprintf('/objednavka/%s/%s/%s',
            $storage->place->id->toRfc4122(),
            $storage->storageType->id->toRfc4122(),
            $storage->id->toRfc4122()
        );
        $this->assertResponseRedirects($expectedUrl);
    }

    public function testPaymentPageShowsOrderDetailsAfterTermsAccepted(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        $this->setTermsAndSignatureViaDb($order);

        $this->client->request('GET', '/objednavka/'.$orderId.'/platba');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Platba objednávky');
        $this->assertSelectorExists('#pay-button');
    }

    public function testPaymentInitiateReturnsGatewayUrl(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);
        $orderId = $order->id->toRfc4122();

        $this->setTermsAndSignatureViaDb($order);

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

        $this->setTermsAndSignatureViaDb($order);

        // Initiate payment
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

        $this->setTermsAndSignatureViaDb($order);

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

        $this->setTermsAndSignatureViaDb($order);

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

    public function testPaymentPageRedirectsToOrderCreateWhenNoSignature(): void
    {
        // The PAID order in fixtures has termsAcceptedAt set but no signature
        $order = $this->findOrderByStatus(OrderStatus::PAID);
        $orderId = $order->id->toRfc4122();
        $storage = $order->storage;

        $this->client->request('GET', '/objednavka/'.$orderId.'/platba');

        $expectedUrl = sprintf('/objednavka/%s/%s/%s',
            $storage->place->id->toRfc4122(),
            $storage->storageType->id->toRfc4122(),
            $storage->id->toRfc4122()
        );
        // No signature -> redirect to order create page
        $this->assertResponseRedirects($expectedUrl);
    }

    private function setTermsAndSignatureViaDb(Order $order): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(Order::class, 'o')
            ->set('o.termsAcceptedAt', ':termsAcceptedAt')
            ->set('o.signaturePath', ':path')
            ->set('o.signingMethod', ':method')
            ->set('o.signedAt', ':signedAt')
            ->where('o.id = :id')
            ->setParameter('termsAcceptedAt', new \DateTimeImmutable())
            ->setParameter('path', '/tmp/test_signature.png')
            ->setParameter('method', 'draw')
            ->setParameter('signedAt', new \DateTimeImmutable())
            ->setParameter('id', $order->id)
            ->getQuery()
            ->execute();
        $this->entityManager->clear();
    }

    private function setOrderSessionData(bool $unlimited = false): void
    {
        /** @var SessionFactoryInterface $sessionFactory */
        $sessionFactory = static::getContainer()->get('session.factory');
        $session = $sessionFactory->createSession();
        $session->set('order_form_data', [
            'email' => 'test-new-order@example.com',
            'firstName' => 'Test',
            'lastName' => 'Objednávka',
            'phone' => '+420123456789',
            'plainPassword' => 'password123',
            'invoiceToCompany' => false,
            'companyName' => null,
            'companyId' => null,
            'companyVatId' => null,
            'billingStreet' => null,
            'billingCity' => null,
            'billingPostalCode' => null,
            'rentalType' => $unlimited ? 'unlimited' : 'limited',
            'startDate' => '2025-06-22',
            'endDate' => $unlimited ? null : '2025-07-22',
        ]);
        $session->save();

        $this->client->getCookieJar()->set(
            new Cookie($session->getName(), $session->getId())
        );
    }

    private function buildAcceptUrl(Storage $storage): string
    {
        return sprintf('/objednavka/%s/%s/%s/prijmout',
            $storage->place->id->toRfc4122(),
            $storage->storageType->id->toRfc4122(),
            $storage->id->toRfc4122()
        );
    }

    private function findAvailableStorage(): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->join('s.place', 'p')
            ->join('s.storageType', 'st')
            ->where('s.status = :status')
            ->andWhere('p.isActive = true')
            ->andWhere('st.isActive = true')
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($storage instanceof Storage, 'No available storage found in fixtures');

        return $storage;
    }

    private function createValidPngDataUrl(): string
    {
        $image = imagecreatetruecolor(1, 1);
        $white = imagecolorallocate($image, 255, 255, 255);
        \assert(false !== $white);
        imagefill($image, 0, 0, $white);

        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();
        \assert(false !== $pngData);

        return 'data:image/png;base64,'.base64_encode($pngData);
    }

    private function findOrderByStatus(OrderStatus $status): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => $status]);
        \assert($order instanceof Order, sprintf('Order with status "%s" not found in fixtures', $status->value));

        return $order;
    }
}
