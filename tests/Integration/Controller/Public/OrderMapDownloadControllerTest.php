<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Service\OrderStatusUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderMapDownloadControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private OrderStatusUrlGenerator $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->urlGenerator = $container->get(OrderStatusUrlGenerator::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testSignedUrlReturnsPng(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::COMPLETED);

        $this->requestSigned($this->urlGenerator->generateMapDownload($order));

        $this->assertResponseIsSuccessful();
        $this->assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertNotEmpty($this->client->getResponse()->getContent());
    }

    public function testSignedUrlWithDownloadFlagSetsAttachmentDisposition(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::COMPLETED);

        $this->requestSigned($this->urlGenerator->generateMapDownload($order, forDownload: true));

        $this->assertResponseIsSuccessful();
        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringStartsWith('attachment', $disposition);
    }

    public function testUnsignedRequestReturns403(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::COMPLETED);

        $this->client->request('GET', '/objednavka/'.$order->id->toRfc4122().'/dokumenty/mapa.png');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonCompletedOrderWithSignedUrlReturns404(): void
    {
        $reserved = $this->findOrderByStatus(OrderStatus::RESERVED);

        $this->requestSigned($this->urlGenerator->generateMapDownload($reserved));

        $this->assertResponseStatusCodeSame(404);
    }

    private function findOrderByStatus(OrderStatus $status): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => $status]);
        \assert($order instanceof Order, sprintf('No order with status %s in fixtures', $status->value));

        return $order;
    }

    private function requestSigned(string $absoluteUrl): void
    {
        $parsed = parse_url($absoluteUrl);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $host = ($parsed['host'] ?? 'localhost').(isset($parsed['port']) ? ':'.$parsed['port'] : '');

        $this->client->request('GET', $path.$query, [], [], ['HTTP_HOST' => $host]);
    }
}
