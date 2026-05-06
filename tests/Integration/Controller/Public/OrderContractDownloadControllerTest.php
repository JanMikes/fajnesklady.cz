<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Service\OrderStatusUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contract download happy-path requires LibreOffice (DocumentPdfConverter)
 * which is environment-dependent. We exercise the gating logic (signature,
 * order status, missing artefacts) — the actual DOCX→PDF conversion is
 * covered elsewhere.
 */
class OrderContractDownloadControllerTest extends WebTestCase
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

    public function testUnsignedRequestReturns403(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::COMPLETED);

        $this->client->request('GET', '/objednavka/'.$order->id->toRfc4122().'/dokumenty/smlouva.pdf');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonCompletedOrderWithSignedUrlReturns404(): void
    {
        $reserved = $this->findOrderByStatus(OrderStatus::RESERVED);

        $this->requestSigned($this->urlGenerator->generateContractDownload($reserved));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonexistentOrderWithSignedUrlReturns403(): void
    {
        $real = $this->findOrderByStatus(OrderStatus::COMPLETED);
        $signedReal = $this->urlGenerator->generateContractDownload($real);
        // Replace the id with a missing one — signature breaks → 403, not 404.
        $tampered = str_replace($real->id->toRfc4122(), Uuid::v7()->toRfc4122(), $signedReal);

        $this->requestSigned($tampered);

        $this->assertResponseStatusCodeSame(403);
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
