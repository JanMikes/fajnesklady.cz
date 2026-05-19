<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Service\OrderStatusUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderInvoiceDownloadControllerTest extends WebTestCase
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

    public function testSignedUrlReturnsPdfForInvoiceWithPdf(): void
    {
        $invoice = $this->findInvoiceWithPdf();
        $order = $invoice->order;

        $this->requestSigned($this->urlGenerator->generateInvoiceDownload($order, $invoice));

        $this->assertResponseIsSuccessful();
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringStartsWith('inline', $disposition);
    }

    public function testSignedUrlWithDownloadFlagSetsAttachmentDisposition(): void
    {
        $invoice = $this->findInvoiceWithPdf();
        $order = $invoice->order;

        $this->requestSigned($this->urlGenerator->generateInvoiceDownload($order, $invoice, forDownload: true));

        $this->assertResponseIsSuccessful();
        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringStartsWith('attachment', $disposition);
    }

    public function testUnsignedRequestReturns403(): void
    {
        $invoice = $this->findInvoiceWithPdf();
        $order = $invoice->order;

        $this->client->request(
            'GET',
            sprintf(
                '/objednavka/%s/dokumenty/faktura/%s.pdf',
                $order->id->toRfc4122(),
                $invoice->id->toRfc4122(),
            ),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    private function findInvoiceWithPdf(): Invoice
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy(['status' => OrderStatus::COMPLETED]);
        foreach ($orders as $order) {
            $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['order' => $order]);
            if (null !== $invoice && $invoice->hasPdf()) {
                return $invoice;
            }
        }

        throw new \LogicException('No completed order with PDF invoice in fixtures.');
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
