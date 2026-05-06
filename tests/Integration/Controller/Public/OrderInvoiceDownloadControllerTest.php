<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class OrderInvoiceDownloadControllerTest extends WebTestCase
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

    public function testCompletedOrderReturnsPdf(): void
    {
        $order = $this->findCompletedOrderWithInvoicePdf();

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringStartsWith('attachment', $disposition);
    }

    public function testCompletedOrderWithoutInvoiceReturns404(): void
    {
        // ContractFixtures seeds a completed order with a terminated contract
        // and no invoice. The controller must 404 when no invoice exists.
        $order = $this->findCompletedOrderWithoutInvoice();

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonCompletedOrderReturns404(): void
    {
        $reserved = $this->findOrderByStatus(OrderStatus::RESERVED);

        $this->client->request('GET', $this->buildUrl($reserved));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidUuidReturns404(): void
    {
        $this->client->request('GET', '/objednavka/not-a-uuid/dokumenty/faktura.pdf');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonexistentOrderReturns404(): void
    {
        $this->client->request('GET', '/objednavka/'.Uuid::v7()->toRfc4122().'/dokumenty/faktura.pdf');

        $this->assertResponseStatusCodeSame(404);
    }

    private function buildUrl(Order $order): string
    {
        return '/objednavka/'.$order->id->toRfc4122().'/dokumenty/faktura.pdf';
    }

    private function findOrderByStatus(OrderStatus $status): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => $status]);
        \assert($order instanceof Order, sprintf('No order with status %s in fixtures', $status->value));

        return $order;
    }

    private function findCompletedOrderWithInvoicePdf(): Order
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy(['status' => OrderStatus::COMPLETED]);
        foreach ($orders as $order) {
            $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['order' => $order]);
            if (null !== $invoice && $invoice->hasPdf()) {
                return $order;
            }
        }

        throw new \LogicException('No completed order with PDF invoice in fixtures.');
    }

    private function findCompletedOrderWithoutInvoice(): Order
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy(['status' => OrderStatus::COMPLETED]);
        foreach ($orders as $order) {
            $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['order' => $order]);
            if (null === $invoice) {
                return $order;
            }
        }

        throw new \LogicException('No completed order without invoice in fixtures.');
    }
}
